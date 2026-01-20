<?php

class AssignmentFactory
{
    private $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Publishes a new assignment or updates an existing one, linking it to classes/students.
     * Generates unique access tokens for each class-assignment scope.
     * 
     * @param int $teacherId
     * @param array $data Input data (title, description, dates, settings)
     * @param array $classIds List of class IDs to assign
     * @param array $studentIds List of individual student IDs to assign
     * @param int|null $assignmentId If provided, updates existing assignment
     * @return int The ID of the created/updated assignment
     */
    public function publish(int $teacherId, array $data, array $classIds = [], array $studentIds = [], ?int $assignmentId = null): int
    {

        $this->pdo->beginTransaction();
        try {
            // 1. Prepare Data
            $open_at = $this->formatDate($data['open_at'] ?? null);
            $due_at = $this->formatDate($data['due_at'] ?? null);
            $close_at = $this->formatDate($data['close_at'] ?? null);

            // 2. Insert or Update Assignment Master Record
            if ($assignmentId && $assignmentId > 0) {
                $stmt = $this->pdo->prepare('UPDATE assignments
                    SET test_id=:test_id, title=:title, description=:description, is_published=:pub,
                        open_at=:open_at, due_at=:due_at, close_at=:close_at,
                        attempt_limit=:limitv, shuffle_questions=:shuffle
                    WHERE id = :id AND assigned_by_teacher_id = :tid');
                $stmt->execute([
                    ':test_id' => $data['test_id'],
                    ':title' => $data['title'],
                    ':description' => $data['description'],
                    ':pub' => $data['is_published'],
                    ':open_at' => $open_at,
                    ':due_at' => $due_at,
                    ':close_at' => $close_at,
                    ':limitv' => $data['attempt_limit'],
                    ':shuffle' => $data['shuffle_questions'],
                    ':id' => $assignmentId,
                    ':tid' => $teacherId,
                ]);

                // Clear existing scopes to re-generate (simplest strategy for now, or could diff)
                // Keeping tokens persistent would be better but for MVP we might wipe. 
                // Let's wipe for now to ensure clean slate as per "One-Touch" logic simulation.
                $this->pdo->prepare('DELETE FROM assignment_classes WHERE assignment_id = :id')->execute([':id' => $assignmentId]);
                $this->pdo->prepare('DELETE FROM assignment_students WHERE assignment_id = :id')->execute([':id' => $assignmentId]);
            } else {
                $stmt = $this->pdo->prepare('
                    INSERT INTO assignments
                        (test_id, assigned_by_teacher_id, title, description, is_published,
                         open_at, due_at, close_at, attempt_limit, shuffle_questions)
                    VALUES
                        (:test_id, :tid, :title, :description, :pub,
                         :open_at, :due_at, :close_at, :limitv, :shuffle)
                ');
                $stmt->execute([
                    ':test_id' => $data['test_id'],
                    ':tid' => $teacherId,
                    ':title' => $data['title'],
                    ':description' => $data['description'],
                    ':pub' => $data['is_published'],
                    ':open_at' => $open_at,
                    ':due_at' => $due_at,
                    ':close_at' => $close_at,
                    ':limitv' => $data['attempt_limit'],
                    ':shuffle' => $data['shuffle_questions'],
                ]);
                $assignmentId = (int) $this->pdo->lastInsertId();
            }

            // 3. Generate Class Scopes with Tokens
            if (!empty($classIds)) {
                $ins = $this->pdo->prepare('INSERT INTO assignment_classes (assignment_id, class_id, access_token) VALUES (:aid, :cid, :token)');
                foreach ($classIds as $cid) {
                    $token = $this->generateToken();
                    $ins->execute([':aid' => $assignmentId, ':cid' => $cid, ':token' => $token]);
                }
            }

            // 4. Generate Student Scopes
            if (!empty($studentIds)) {
                $ins = $this->pdo->prepare('INSERT INTO assignment_students (assignment_id, student_id) VALUES (:aid, :sid)');
                foreach ($studentIds as $sid) {
                    $ins->execute([':aid' => $assignmentId, ':sid' => $sid]);
                }
            }

            $this->pdo->commit();
            return $assignmentId;

        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    private function formatDate(?string $s): ?string
    {
        if ($s === null || $s === '')
            return null;
        return str_replace('T', ' ', $s) . (strlen($s) <= 16 ? ':00' : '');
    }

    private function generateToken($length = 8)
    {
        return substr(bin2hex(random_bytes($length)), 0, $length);
    }
}
