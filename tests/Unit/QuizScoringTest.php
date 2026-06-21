<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class QuizScoringTest extends TestCase
{
    public function testTrueFalseCorrect(): void
    {
        $q = ['id' => 1, 'question_type' => 'true_false', 'choices' => '["True","False"]', 'correct_index' => 0, 'points' => null];
        $this->assertSame(5.0, score_quiz_question($q, 0, 5.0));
        $this->assertSame(0.0, score_quiz_question($q, 1, 5.0));
    }

    public function testMultiSelectAllOrNothing(): void
    {
        $q = [
            'id' => 2,
            'question_type' => 'multi_select',
            'choices' => json_encode(['options' => ['A', 'B', 'C'], 'correct' => [0, 2]]),
            'correct_index' => 0,
            'points' => null,
        ];
        $this->assertSame(10.0, score_quiz_question($q, [0, 2], 10.0));
        $this->assertSame(0.0, score_quiz_question($q, [0, 1], 10.0));
    }

    public function testMatchingPartialCredit(): void
    {
        $q = [
            'id' => 3,
            'question_type' => 'matching',
            'choices' => json_encode(['left' => ['L1', 'L2'], 'right' => ['R1', 'R2'], 'pairs' => [0, 1]]),
            'correct_index' => 0,
            'points' => null,
        ];
        $this->assertSame(4.0, score_quiz_question($q, [0 => 0, 1 => 1], 4.0));
        $this->assertSame(2.0, score_quiz_question($q, [0 => 0, 1 => 0], 4.0));
    }
}