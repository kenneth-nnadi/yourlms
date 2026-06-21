<?php
declare(strict_types=1);

/**
 * IMS Common Cartridge (IMS CC 1.1+) course importer.
 * Supports Canvas exports and standard IMS CC organization manifests.
 */
final class ImsccImporter
{
    private PDO $pdo;
    private string $root;
    private array $config;
    private array $resources = [];
    private array $wikiItemMap = [];
    private int $courseId = 0;
    private string $assetBaseUrl;
    private string $assetRelDir = 'imscc/web_resources';
    private bool $replaceExisting = false;
    private ?SimpleXMLElement $manifestXml = null;
    private string $manifestTitle = 'Imported course';

    public function __construct(PDO $pdo, string $extractedRoot, array $config)
    {
        $this->pdo = $pdo;
        $this->root = rtrim($extractedRoot, '/');
        $this->config = $config;
        $this->assetBaseUrl = rtrim($config['base_url'], '/') . '/serve.php?f=';
    }

    public function import(int $instructorId, ?int $targetCourseId = null, bool $replaceExisting = false): array
    {
        $this->replaceExisting = $replaceExisting;
        $this->loadManifest();

        if ($targetCourseId !== null) {
            $check = $this->pdo->prepare('SELECT id FROM courses WHERE id = ?');
            $check->execute([$targetCourseId]);
            if (!$check->fetch()) {
                throw new RuntimeException('Target course not found.');
            }
            $this->courseId = $targetCourseId;
            if ($replaceExisting) {
                clear_course_content($this->pdo, $this->courseId);
                $this->updateCourseFromManifest($this->courseId);
            }
            $this->ensureInstructorEnrolled($instructorId);
        } else {
            $this->courseId = $this->createCourse($instructorId);
            $this->enrollInstructor($instructorId);
        }

        $this->assetRelDir = 'imscc/course_' . $this->courseId . '/web_resources';
        $this->assetBaseUrl = rtrim($this->config['base_url'], '/') . '/serve.php?f=';
        $this->prepareAssetStorage();
        $stats = $this->importModules();
        $this->rewriteStoredHtmlLinks();
        return ['course_id' => $this->courseId, 'stats' => $stats, 'replaced' => $replaceExisting];
    }

    private function loadManifest(): void
    {
        $manifest = $this->root . '/imsmanifest.xml';
        if (!is_file($manifest)) {
            throw new RuntimeException('imsmanifest.xml not found — is this a valid IMS Common Cartridge package?');
        }
        $xml = simplexml_load_file($manifest);
        if (!$xml) {
            throw new RuntimeException('Could not parse imsmanifest.xml');
        }
        $this->manifestXml = $xml;
        $this->registerManifestNamespaces($xml);
        $this->manifestTitle = $this->extractManifestTitle($xml);

        if (isset($xml->resources->resource)) {
            foreach ($xml->resources->resource as $res) {
                $id = (string) $res['identifier'];
                $href = (string) ($res['href'] ?? '');
                if ($href === '' && isset($res->file[0])) {
                    $href = (string) $res->file[0]['href'];
                }
                $files = [];
                foreach ($res->file ?? [] as $file) {
                    $files[] = (string) $file['href'];
                }
                $this->resources[$id] = [
                    'type' => (string) $res['type'],
                    'href' => $href,
                    'files' => $files,
                ];
            }
        }
    }

    private function registerManifestNamespaces(SimpleXMLElement $xml): void
    {
        $xml->registerXPathNamespace('imscp', 'http://www.imsglobal.org/xsd/imsccv1p1/imscp_v1p1');
        $xml->registerXPathNamespace('lom', 'http://ltsc.ieee.org/xsd/imsccv1p1/LOM/resource');
        $xml->registerXPathNamespace('lomimscc', 'http://ltsc.ieee.org/xsd/imsccv1p1/LOM/manifest');
    }

    private function extractManifestTitle(SimpleXMLElement $xml): string
    {
        $this->registerManifestNamespaces($xml);
        $nodes = $xml->xpath('//lomimscc:string') ?: $xml->xpath('//lom:string') ?: [];
        foreach ($nodes as $node) {
            $text = trim(str_replace('_', ' ', (string) $node));
            if ($text !== '') {
                return $text;
            }
        }
        return 'Imported course';
    }

    private function updateCourseFromManifest(int $courseId): void
    {
        $title = $this->manifestTitle;
        $context = $this->root . '/course_settings/context.xml';
        if (is_file($context)) {
            $cx = simplexml_load_file($context);
            if ($cx && (string) $cx->course_name !== '') {
                $title = str_replace('_', ' ', (string) $cx->course_name);
            }
        }
        $description = '';
        foreach (['/course_settings/syllabus.html', '/syllabus.html'] as $syllabusPath) {
            $text = $this->htmlToText($this->readFile($this->root . $syllabusPath));
            if ($text !== '') {
                $description = mb_substr($text, 0, 2000);
                break;
            }
        }
        $this->pdo->prepare('UPDATE courses SET name = ?, description = ? WHERE id = ?')
            ->execute([$title, $description ?: null, $courseId]);
    }

    private function ensureInstructorEnrolled(int $instructorId): void
    {
        $enroll = $this->pdo->prepare(sql_enroll_insert_ignore($this->config));
        $enroll->execute([$this->courseId, $instructorId, 'instructor']);
    }

    private function prepareAssetStorage(): void
    {
        $dest = $this->config['upload_dir'] . '/' . $this->assetRelDir;
        if (is_dir($dest)) {
            $this->rrmdir($dest);
        }
        foreach (['web_resources', 'resources', 'media'] as $folder) {
            $src = $this->root . '/' . $folder;
            if (is_dir($src)) {
                $this->rcopy($src, $dest . '/' . $folder);
            }
        }
    }

    private function createCourse(int $instructorId): int
    {
        $title = $this->manifestTitle;
        $context = $this->root . '/course_settings/context.xml';
        if (is_file($context)) {
            $cx = simplexml_load_file($context);
            if ($cx && (string) $cx->course_name !== '') {
                $title = str_replace('_', ' ', (string) $cx->course_name);
            }
        }

        $description = '';
        foreach (['/course_settings/syllabus.html', '/syllabus.html'] as $syllabusPath) {
            $text = $this->htmlToText($this->readFile($this->root . $syllabusPath));
            if ($text !== '') {
                $description = mb_substr($text, 0, 2000);
                break;
            }
        }

        $code = strtoupper(preg_replace('/[^A-Z0-9]/', '', substr($title, 0, 8)) ?: 'IMSCOURSE');
        $stmt = $this->pdo->prepare(
            'INSERT INTO courses (code, name, term, description, color, created_by) VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$code, $title, 'Imported', $description, '#0055a4', $instructorId]);
        return (int) $this->pdo->lastInsertId();
    }

    private function enrollInstructor(int $instructorId): void
    {
        $enroll = $this->pdo->prepare('INSERT INTO enrollments (course_id, user_id, role) VALUES (?, ?, ?)');
        $enroll->execute([$this->courseId, $instructorId, 'instructor']);
        foreach ($this->pdo->query("SELECT id FROM users WHERE role = 'student'") as $row) {
            $enroll->execute([$this->courseId, $row['id'], 'student']);
        }
    }

    private function importModules(): array
    {
        $metaPath = $this->root . '/course_settings/module_meta.xml';
        if (is_file($metaPath)) {
            return $this->importCanvasModules($metaPath);
        }
        return $this->importOrganizationModules();
    }

    private function importCanvasModules(string $metaPath): array
    {
        $xml = simplexml_load_file($metaPath);
        if (!$xml) {
            throw new RuntimeException('Could not parse module_meta.xml');
        }

        $stats = $this->emptyStats();

        foreach ($xml->module as $mod) {
            $pos = (int) $mod->position - 1;
            $modPublished = canvas_workflow_is_published((string) ($mod->workflow_state ?? 'active')) ? 1 : 0;
            $this->pdo->prepare('INSERT INTO modules (course_id, title, position, published) VALUES (?, ?, ?, ?)')
                ->execute([$this->courseId, (string) $mod->title, max(0, $pos), $modPublished]);
            $moduleId = (int) $this->pdo->lastInsertId();
            $stats['modules']++;

            foreach ($mod->items->item as $item) {
                $this->importModuleItem($moduleId, $item, $stats);
            }
        }

        return $stats;
    }

    private function importOrganizationModules(): array
    {
        if (!$this->manifestXml || !isset($this->manifestXml->organizations->organization)) {
            throw new RuntimeException('No module structure found — expected module_meta.xml or IMS organization items in the package.');
        }

        $stats = $this->emptyStats();
        $org = $this->manifestXml->organizations->organization[0] ?? $this->manifestXml->organizations->organization;
        $moduleItems = $this->organizationModuleRoots($org);
        $pos = 0;

        foreach ($moduleItems as $modItem) {
            $title = trim((string) ($modItem->title ?? 'Module ' . ($pos + 1)));
            if ($title === '') {
                $title = 'Module ' . ($pos + 1);
            }
            $this->pdo->prepare('INSERT INTO modules (course_id, title, position, published) VALUES (?, ?, ?, 1)')
                ->execute([$this->courseId, $title, $pos]);
            $moduleId = (int) $this->pdo->lastInsertId();
            $stats['modules']++;
            $itemPos = 0;
            foreach ($modItem->item ?? [] as $child) {
                $this->importOrganizationItem($moduleId, $child, $itemPos++, $stats);
            }
            if (!isset($modItem->item) && (string) ($modItem['identifierref'] ?? '') !== '') {
                $this->importResourceItem($moduleId, $title, (string) $modItem['identifierref'], 0, $stats);
            }
            $pos++;
        }

        return $stats;
    }

    private function organizationModuleRoots(SimpleXMLElement $org): array
    {
        $top = [];
        foreach ($org->item ?? [] as $item) {
            $top[] = $item;
        }
        if (count($top) === 1 && isset($top[0]->item)) {
            $roots = [];
            foreach ($top[0]->item as $child) {
                $roots[] = $child;
            }
            return $roots ?: $top;
        }
        return $top;
    }

    private function importOrganizationItem(int $moduleId, SimpleXMLElement $item, int $position, array &$stats): void
    {
        $title = trim((string) ($item->title ?? ''));
        $ref = (string) ($item['identifierref'] ?? '');
        if ($ref !== '') {
            $this->importResourceItem($moduleId, $title ?: 'Item', $ref, $position, $stats);
            return;
        }
        if ($title !== '') {
            $this->insertPageItem($moduleId, $title, '<p>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</p>', $position, null, 1);
            $stats['items']++;
            $stats['pages']++;
        }
    }

    private function importResourceItem(int $moduleId, string $title, string $ref, int $position, array &$stats): void
    {
        $res = $this->resources[$ref] ?? null;
        if (!$res) {
            if ($title !== '') {
                $this->insertPageItem($moduleId, $title, '<p>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</p>', $position, null, 1);
                $stats['items']++;
                $stats['pages']++;
            }
            return;
        }

        $type = strtolower($res['type']);
        $href = $res['href'];

        if (str_contains($type, 'imsdt') || str_contains($type, 'discussion')) {
            $prompt = $this->readDiscussionPrompt($ref);
            $discId = $this->createDiscussion($title, $prompt);
            $this->insertItem($moduleId, $title, 'discussion', $position, ['ref_id' => $discId, 'published' => 1]);
            $stats['items']++;
            $stats['discussions']++;
            return;
        }

        if (str_contains($type, 'imsqti') || str_contains($type, 'assessment')) {
            $quizId = $this->importQuiz($ref, $href);
            if ($quizId) {
                $this->insertItem($moduleId, $title, 'quiz', $position, ['ref_id' => $quizId, 'published' => 1]);
                $stats['quizzes']++;
            }
            $stats['items']++;
            return;
        }

        if (str_contains($type, 'imswl') || str_contains($type, 'weblink')) {
            $url = $this->resolveWebLink($ref);
            $this->insertItem($moduleId, $title, 'external', $position, ['content' => $url, 'published' => 1]);
            $stats['items']++;
            $stats['links']++;
            return;
        }

        if (str_contains($type, 'webcontent') || preg_match('/\.(html?|xhtml)$/i', $href)) {
            $body = $this->extractWikiBody($this->readFile($this->root . '/' . $href));
            $body = $this->rewriteHtml($body ?: '<p>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</p>');
            $this->insertPageItem($moduleId, $title, $body, $position, $ref, 1);
            $stats['items']++;
            $stats['pages']++;
            return;
        }

        $fileInfo = $this->resolveAttachment($ref);
        if ($fileInfo) {
            $this->insertItem($moduleId, $title, 'file', $position, [
                'file_path' => $fileInfo['path'],
                'content' => $fileInfo['mime'] ?? null,
                'published' => 1,
            ]);
            $stats['items']++;
            $stats['files']++;
            return;
        }

        if ($title !== '') {
            $this->insertPageItem($moduleId, $title, '<p>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</p>', $position, null, 1);
            $stats['items']++;
            $stats['pages']++;
        }
    }

    private function emptyStats(): array
    {
        return [
            'modules' => 0, 'items' => 0, 'pages' => 0, 'files' => 0, 'links' => 0,
            'discussions' => 0, 'quizzes' => 0, 'assignments' => 0,
        ];
    }

    private function importModuleItem(int $moduleId, SimpleXMLElement $item, array &$stats): void
    {
        $type = (string) $item->content_type;
        $title = trim((string) $item->title);
        $position = max(0, (int) $item->position - 1);
        $ref = (string) ($item->identifierref ?? '');
        $published = canvas_workflow_is_published((string) ($item->workflow_state ?? 'active')) ? 1 : 0;

        switch ($type) {
            case 'ContextModuleSubHeader':
                $html = '<h3 class="module-subheader">' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</h3>';
                $this->insertPageItem($moduleId, $title, $html, $position, null, $published);
                $stats['items']++;
                $stats['pages']++;
                break;

            case 'WikiPage':
                $href = $this->resources[$ref]['href'] ?? '';
                $body = $this->extractWikiBody($this->readFile($this->root . '/' . $href));
                $body = $this->rewriteHtml($body);
                $itemId = $this->insertPageItem($moduleId, $title, $body, $position, $ref, $published);
                if ($ref) {
                    $this->wikiItemMap[$ref] = $itemId;
                }
                $stats['items']++;
                $stats['pages']++;
                break;

            case 'ExternalUrl':
                $url = trim((string) ($item->url ?? ''));
                if ($url === '' && $ref) {
                    $url = $this->resolveWebLink($ref);
                }
                $this->insertItem($moduleId, $title, 'external', $position, ['content' => $url, 'published' => $published]);
                $stats['items']++;
                $stats['links']++;
                break;

            case 'Attachment':
                $fileInfo = $this->resolveAttachment($ref);
                if ($fileInfo) {
                    $this->insertItem($moduleId, $title, 'file', $position, [
                        'file_path' => $fileInfo['path'],
                        'content' => $fileInfo['mime'] ?? null,
                        'published' => $published,
                    ]);
                    $stats['files']++;
                } else {
                    $this->insertPageItem($moduleId, $title, '<p><em>File missing: ' . e($title) . '</em></p>', $position, null, $published);
                    $stats['pages']++;
                }
                $stats['items']++;
                break;

            case 'DiscussionTopic':
                $prompt = $this->readDiscussionPrompt($ref);
                $discId = $this->createDiscussion($title, $prompt);
                $this->insertItem($moduleId, $title, 'discussion', $position, ['ref_id' => $discId, 'published' => $published]);
                $stats['items']++;
                $stats['discussions']++;
                break;

            case 'Quizzes::Quiz':
                $quizId = $this->importQuiz($ref, $this->resources[$ref]['href'] ?? null);
                if ($quizId) {
                    $this->insertItem($moduleId, $title, 'quiz', $position, ['ref_id' => $quizId, 'published' => $published]);
                    $stats['quizzes']++;
                }
                $stats['items']++;
                break;

            case 'Assignment':
                $assignId = $this->importAssignment($ref, $title);
                if ($assignId) {
                    $this->insertItem($moduleId, $title, 'assignment', $position, ['ref_id' => $assignId, 'published' => $published]);
                    $stats['assignments']++;
                }
                $stats['items']++;
                break;

            default:
                if ($title !== '') {
                    $this->insertPageItem($moduleId, $title, '<p>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</p>', $position, null, $published);
                    $stats['items']++;
                    $stats['pages']++;
                }
        }
    }

    private function insertPageItem(int $moduleId, string $title, string $html, int $position, ?string $canvasId, int $published = 1): int
    {
        $this->insertItem($moduleId, $title, 'page', $position, [
            'content' => $html,
            'content_format' => 'html',
            'canvas_identifier' => $canvasId,
            'published' => $published,
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    private function insertItem(int $moduleId, string $title, string $type, int $position, array $extra = []): void
    {
        $this->pdo->prepare(
            'INSERT INTO module_items (module_id, title, item_type, content, content_format, canvas_identifier, ref_id, file_path, position, published)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $moduleId,
            mb_substr($title, 0, 255),
            $type,
            $extra['content'] ?? null,
            $extra['content_format'] ?? 'text',
            $extra['canvas_identifier'] ?? null,
            $extra['ref_id'] ?? null,
            $extra['file_path'] ?? null,
            $position,
            $extra['published'] ?? 1,
        ]);
    }

    private function extractWikiBody(string $html): string
    {
        if (preg_match('/<body[^>]*>(.*)<\/body>/is', $html, $m)) {
            return trim($m[1]);
        }
        return $html;
    }

    private function appUrl(string $path): string
    {
        $base = rtrim($this->config['base_url'], '/');
        return $base . '/' . ltrim($path, '/');
    }

    private function rewriteHtml(string $html): string
    {
        $base = $this->assetBaseUrl;
        $html = preg_replace_callback(
            '#\$IMS-CC-FILEBASE\$/([^"\'\s>]+)#i',
            fn ($m) => $base . rawurlencode($this->assetRelDir . '/' . rawurldecode($m[1])),
            $html
        ) ?? $html;

        $html = preg_replace_callback(
            '#\$WIKI_REFERENCE\$/pages/([a-z0-9]+)#i',
            function ($m) {
                $id = $m[1];
                if (isset($this->wikiItemMap[$id])) {
                    return $this->appUrl('page.php?course_id=' . $this->courseId . '&item_id=' . $this->wikiItemMap[$id]);
                }
                return '#';
            },
            $html
        ) ?? $html;

        $html = str_replace('$CANVAS_COURSE_REFERENCE$', $this->appUrl('course.php?id=' . $this->courseId), $html);
        $html = preg_replace('#\$CANVAS_OBJECT_REFERENCE\$/modules/[a-z0-9]+#i', $this->appUrl('course.php?id=' . $this->courseId), $html) ?? $html;

        return $html;
    }

    private function resolveWebLink(string $ref): string
    {
        $href = $this->resources[$ref]['href'] ?? ($ref . '.xml');
        $path = $this->root . '/' . $href;
        if (!is_file($path)) {
            $path = $this->root . '/' . $ref . '.xml';
        }
        if (!is_file($path)) {
            return '';
        }
        $xml = simplexml_load_file($path);
        if (!$xml) {
            return '';
        }
        $xml->registerXPathNamespace('wl', 'http://www.imsglobal.org/xsd/imsccv1p1/imswl_v1p1');
        $url = $xml->url['href'] ?? $xml->url->attributes()->href ?? null;
        return $url ? (string) $url : '';
    }

    private function resolveAttachment(string $ref): ?array
    {
        $res = $this->resources[$ref] ?? [];
        $href = $res['href'] ?? '';
        if ($href === '' && !empty($res['files'][0])) {
            $href = $res['files'][0];
        }
        if ($href === '') {
            return null;
        }
        $src = $this->root . '/' . $href;
        if (!is_file($src)) {
            return null;
        }
        $rel = 'imscc/' . $href;
        $dest = $this->config['upload_dir'] . '/' . $rel;
        if (!is_file($dest)) {
            if (!is_dir(dirname($dest))) {
                mkdir(dirname($dest), 0755, true);
            }
            copy($src, $dest);
        }
        return [
            'path' => $rel,
            'mime' => is_file($dest) ? (mime_content_type($dest) ?: 'application/octet-stream') : 'application/octet-stream',
        ];
    }

    private function readDiscussionPrompt(string $ref): string
    {
        $href = $this->resources[$ref]['href'] ?? '';
        $candidates = array_filter([
            $this->root . '/' . $href,
            $this->root . '/' . $ref . '.xml',
        ]);
        foreach ($candidates as $path) {
            if (!is_file($path)) {
                continue;
            }
            $xml = simplexml_load_file($path);
            if ($xml) {
                $text = (string) ($xml->text ?? '');
                if ($text !== '') {
                    return $this->htmlToText($text);
                }
            }
        }
        return '';
    }

    private function createDiscussion(string $title, string $prompt): int
    {
        $instructor = $this->pdo->query("SELECT id FROM users WHERE role = 'instructor' LIMIT 1")->fetchColumn();
        $this->pdo->prepare('INSERT INTO discussions (course_id, title, prompt, created_by) VALUES (?, ?, ?, ?)')
            ->execute([$this->courseId, $title, $prompt, $instructor ?: null]);
        return (int) $this->pdo->lastInsertId();
    }

    private function importQuiz(string $ref, ?string $href = null): ?int
    {
        $metaPath = $this->root . '/' . $ref . '/assessment_meta.xml';
        $qtiPath = $this->root . '/' . $ref . '/assessment_qti.xml';
        if (!is_file($metaPath) && $href) {
            $metaPath = $this->root . '/' . dirname($href) . '/assessment_meta.xml';
            $qtiPath = $this->root . '/' . ($href ?: $ref . '/assessment_qti.xml');
        }
        if (!is_file($qtiPath) && $href && is_file($this->root . '/' . $href)) {
            $qtiPath = $this->root . '/' . $href;
        }
        if (!is_file($qtiPath)) {
            return null;
        }

        $title = 'Imported quiz';
        $points = 100.0;
        $due = null;
        $description = '';
        if (is_file($metaPath)) {
            $meta = simplexml_load_file($metaPath);
            $title = (string) ($meta->title ?? $title);
            $points = (float) ($meta->points_possible ?? $points);
            $dueStr = (string) ($meta->due_at ?? '');
            $due = $dueStr !== '' ? date('Y-m-d H:i:s', strtotime($dueStr)) : null;
            $description = (string) ($meta->description ?? '');
        }

        $this->pdo->prepare('INSERT INTO quizzes (course_id, title, description, points, due_at) VALUES (?, ?, ?, ?, ?)')
            ->execute([$this->courseId, $title, $description, $points, $due]);
        $quizId = (int) $this->pdo->lastInsertId();

        $qti = simplexml_load_file($qtiPath);
        $qti->registerXPathNamespace('q', 'http://www.imsglobal.org/xsd/ims_qtiasiv1p2');
        $pos = 0;
        foreach ($qti->xpath('//q:item') ?: [] as $qItem) {
            $profile = '';
            foreach ($qItem->itemmetadata->qtimetadata->qtimetadatafield ?? [] as $field) {
                if ((string) $field->fieldlabel === 'cc_profile') {
                    $profile = (string) $field->fieldentry;
                }
            }

            $questionHtml = '';
            foreach ($qItem->presentation->material->mattext ?? [] as $mt) {
                $questionHtml .= (string) $mt;
            }
            $question = $this->htmlToText(html_entity_decode($questionHtml));

            if (str_contains($profile, 'essay')) {
                $this->pdo->prepare(
                    'INSERT INTO quiz_questions (quiz_id, question, question_type, choices, correct_index, position) VALUES (?, ?, ?, ?, 0, ?)'
                )->execute([$quizId, $question, 'essay', '[]', $pos++]);
                continue;
            }

            $choices = [];
            $correct = 0;
            $ci = 0;
            foreach ($qItem->presentation->response_lid->render_choice->response_label ?? [] as $label) {
                $choices[] = $this->htmlToText((string) ($label->material->mattext ?? $label['ident']));
                if ((string) ($label['ident'] ?? '') === 'correct') {
                    $correct = $ci;
                }
                $ci++;
            }
            if ($choices) {
                $this->pdo->prepare(
                    'INSERT INTO quiz_questions (quiz_id, question, question_type, choices, correct_index, position) VALUES (?, ?, ?, ?, ?, ?)'
                )->execute([$quizId, $question, 'choice', json_encode($choices), $correct, $pos++]);
            }
        }

        return $quizId;
    }

    private function importAssignment(string $ref, string $fallbackTitle): ?int
    {
        $settingsPath = $this->root . '/' . $ref . '/assignment_settings.xml';
        if (!is_file($settingsPath)) {
            $href = $this->resources[$ref]['href'] ?? '';
            if ($href) {
                $settingsPath = $this->root . '/' . dirname($href) . '/assignment_settings.xml';
            }
        }
        if (!is_file($settingsPath)) {
            $settingsPath = $this->root . '/' . $ref . '.xml';
        }

        $title = $fallbackTitle;
        $points = 100.0;
        $due = null;
        $description = '';
        $published = 1;

        if (is_file($settingsPath)) {
            $xml = simplexml_load_file($settingsPath);
            if ($xml) {
                $title = (string) ($xml->title ?? $title);
                $dueStr = (string) ($xml->due_at ?? '');
                $due = $dueStr !== '' ? date('Y-m-d H:i:s', strtotime($dueStr)) : null;
                $pointsStr = (string) ($xml->points_possible ?? '');
                if ($pointsStr !== '') {
                    $points = (float) $pointsStr;
                }
                $description = (string) ($xml->description ?? $xml->instructions ?? '');
                if ($description !== '') {
                    $description = $this->htmlToText(html_entity_decode($description));
                }
                $published = canvas_workflow_is_published((string) ($xml->workflow_state ?? 'active')) ? 1 : 0;
            }
        }

        $this->pdo->prepare(
            'INSERT INTO assignments (course_id, title, description, due_at, points) VALUES (?, ?, ?, ?, ?)'
        )->execute([$this->courseId, $title ?: $fallbackTitle, $description ?: null, $due, $points]);
        return (int) $this->pdo->lastInsertId();
    }

    private function readFile(string $path): string
    {
        return is_file($path) ? (string) file_get_contents($path) : '';
    }

    private function rewriteStoredHtmlLinks(): void
    {
        $rows = $this->pdo->query(
            "SELECT mi.id, mi.content FROM module_items mi
             JOIN modules m ON m.id = mi.module_id
             WHERE m.course_id = {$this->courseId} AND mi.content_format = 'html' AND mi.content IS NOT NULL"
        )->fetchAll();
        $update = $this->pdo->prepare('UPDATE module_items SET content = ? WHERE id = ?');
        foreach ($rows as $row) {
            $update->execute([$this->rewriteHtml($row['content']), $row['id']]);
        }
    }

    private function htmlToText(string $html): string
    {
        $html = html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = strip_tags($html);
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;
        return trim($text);
    }

    private function rcopy(string $src, string $dest): void
    {
        if (!is_dir($dest)) {
            mkdir($dest, 0755, true);
        }
        $dir = opendir($src);
        while (($file = readdir($dir)) !== false) {
            if ($file === '.' || $file === '..') {
                continue;
            }
            $s = $src . '/' . $file;
            $d = $dest . '/' . $file;
            is_dir($s) ? $this->rcopy($s, $d) : copy($s, $d);
        }
        closedir($dir);
    }

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $file) {
            if ($file === '.' || $file === '..') {
                continue;
            }
            $path = $dir . '/' . $file;
            is_dir($path) ? $this->rrmdir($path) : unlink($path);
        }
        rmdir($dir);
    }
}

function clear_course_content(PDO $pdo, int $courseId): void
{
    $pdo->prepare('DELETE FROM quiz_attempts WHERE quiz_id IN (SELECT id FROM quizzes WHERE course_id = ?)')->execute([$courseId]);
    $pdo->prepare('DELETE FROM quiz_questions WHERE quiz_id IN (SELECT id FROM quizzes WHERE course_id = ?)')->execute([$courseId]);
    $pdo->prepare('DELETE FROM quizzes WHERE course_id = ?')->execute([$courseId]);
    $pdo->prepare('DELETE FROM submission_comments WHERE submission_id IN (SELECT s.id FROM submissions s JOIN assignments a ON a.id = s.assignment_id WHERE a.course_id = ?)')->execute([$courseId]);
    $pdo->prepare('DELETE FROM submissions WHERE assignment_id IN (SELECT id FROM assignments WHERE course_id = ?)')->execute([$courseId]);
    $pdo->prepare('DELETE FROM assignments WHERE course_id = ?')->execute([$courseId]);
    $pdo->prepare('DELETE FROM discussion_posts WHERE discussion_id IN (SELECT id FROM discussions WHERE course_id = ?)')->execute([$courseId]);
    $pdo->prepare('DELETE FROM discussion_grades WHERE discussion_id IN (SELECT id FROM discussions WHERE course_id = ?)')->execute([$courseId]);
    $pdo->prepare('DELETE FROM discussions WHERE course_id = ?')->execute([$courseId]);
    $pdo->prepare('DELETE FROM announcements WHERE course_id = ?')->execute([$courseId]);
    $pdo->prepare('DELETE FROM course_files WHERE course_id = ?')->execute([$courseId]);
    $pdo->prepare('DELETE FROM module_items WHERE module_id IN (SELECT id FROM modules WHERE course_id = ?)')->execute([$courseId]);
    $pdo->prepare('DELETE FROM modules WHERE course_id = ?')->execute([$courseId]);
}

function import_imscc_zip(PDO $pdo, string $zipPath, array $config, int $instructorId, ?int $targetCourseId = null, bool $replaceExisting = false): array
{
    require_once __DIR__ . '/helpers.php';

    if (!is_file($zipPath)) {
        throw new RuntimeException("Zip not found: {$zipPath}");
    }

    $tmp = $config['upload_dir'] . '/imscc_import_' . bin2hex(random_bytes(4));
    mkdir($tmp, 0755, true);

    $zip = new ZipArchive();
    if ($zip->open($zipPath) !== true) {
        throw new RuntimeException('Could not open zip archive.');
    }
    $zip->extractTo($tmp);
    $zip->close();

    $root = $tmp;
    if (is_file($tmp . '/imsmanifest.xml')) {
        $root = $tmp;
    } else {
        foreach (scandir($tmp) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $sub = $tmp . '/' . $entry;
            if (is_dir($sub) && is_file($sub . '/imsmanifest.xml')) {
                $root = $sub;
                break;
            }
        }
    }

    $importer = new ImsccImporter($pdo, $root, $config);
    return $importer->import($instructorId, $targetCourseId, $replaceExisting);
}