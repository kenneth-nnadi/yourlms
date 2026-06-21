<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class WeightedGradeTest extends TestCase
{
    public function testWeightedSummary(): void
    {
        $groups = [
            ['id' => 1, 'name' => 'Homework', 'weight' => 60],
            ['id' => 2, 'name' => 'Exams', 'weight' => 40],
        ];
        $rows = [
            ['points' => 100, 'group_id' => 1, 'score' => 80],
            ['points' => 100, 'group_id' => 2, 'score' => 90],
        ];
        $w = weighted_grade_summary($groups, $rows);
        $this->assertNotNull($w);
        $this->assertEqualsWithDelta(84.0, $w['weighted_percent'], 0.1);
    }
}