# Publishing workflow

YourLMS mirrors the Canvas pattern: creating an assignment/quiz/discussion is not enough — students only see items that are **placed in a published module** and marked **live**.

## Checklist for instructors

1. **Create** the item (Assignments, Quizzes, or Discussions under Teach).
2. **Add to module** — pick a module and check **Go live** (or add first, then click **Go live**).
3. **Publish the course** if it is still unpublished (Modules page).
4. **Preview as student** from the course home to verify the learner view.
5. Confirm the notification bell (students receive alerts when items go live).

## Visibility rules

| Role | Sees |
|------|------|
| Site instructor | All courses and unpublished content |
| Course instructor / TA | Enrolled courses including drafts |
| Student | Published course + published module items only |
| Guest | Published content, cannot submit |

## Module item types

- **Page** — inline HTML content
- **Assignment / Quiz / Discussion** — links to gradeable activities
- **File** — downloadable material
- **External / LTI** — outbound links or LTI launches

## Common mistakes

| Symptom | Fix |
|---------|-----|
| Students see empty course | Publish modules and course |
| Item missing from course | Add to module + Go live |
| "Could not add to module" (older builds) | Update to latest; ensure module exists |
| Uploaded files invisible | Check `uploads/` permissions |
| Assignment document not shown | Attach file on Teach → Assignments edit form |

## Notifications

When content goes live, enrolled students receive in-app notifications. Email is optional and requires SMTP in `config.local.php`.