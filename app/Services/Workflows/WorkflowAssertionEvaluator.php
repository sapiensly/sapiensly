<?php

namespace App\Services\Workflows;

use App\Models\WorkflowRun;
use App\Models\WorkflowStepRun;

/**
 * Evaluates a closed set of declarative behaviour assertions against a
 * WorkflowRun trace (FR-2.5). The set is intentionally bounded — the builder
 * selects and parametrizes from it, never invents an assertion DSL.
 *
 * Supported assertion shapes:
 *   {type: 'step_reached',     step}
 *   {type: 'step_status',      step, status}
 *   {type: 'output_equals',    step, path, value}
 *   {type: 'output_matches',   step, path, pattern}
 *   {type: 'proposal_emitted', step}
 *   {type: 'no_external_write'}
 */
class WorkflowAssertionEvaluator
{
    private const EXTERNAL_WRITE_TYPES = ['connector.call', 'http.request'];

    /**
     * @param  list<array<string, mixed>>  $assertions
     * @return list<array{type: string, label: string, passed: bool, detail: string}>
     */
    public function evaluate(WorkflowRun $run, array $assertions): array
    {
        $stepsById = [];
        foreach ($run->steps as $stepRun) {
            $stepsById[$stepRun->step_id] = $stepRun;
        }

        return array_map(fn (array $assertion): array => $this->evaluateOne($assertion, $stepsById), $assertions);
    }

    /**
     * @param  array<string, mixed>  $assertion
     * @param  array<string, WorkflowStepRun>  $stepsById
     * @return array{type: string, label: string, passed: bool, detail: string}
     */
    private function evaluateOne(array $assertion, array $stepsById): array
    {
        $type = (string) ($assertion['type'] ?? '');
        $step = (string) ($assertion['step'] ?? '');
        $stepRun = $stepsById[$step] ?? null;

        return match ($type) {
            'step_reached' => $this->result(
                $type,
                "Step {$step} reached",
                $stepRun !== null,
                $stepRun !== null ? 'reached' : 'step did not run',
            ),
            'step_status' => $this->result(
                $type,
                "Step {$step} {$assertion['status']}",
                $stepRun !== null && $stepRun->status === ($assertion['status'] ?? null),
                $stepRun !== null ? "status: {$stepRun->status}" : 'step did not run',
            ),
            'output_equals' => $this->outputEquals($assertion, $stepRun),
            'output_matches' => $this->outputMatches($assertion, $stepRun),
            'proposal_emitted' => $this->result(
                $type,
                "Step {$step} emitted a proposal",
                $stepRun !== null && ($stepRun->output['simulated'] ?? false) === true,
                $stepRun !== null ? 'simulated write preview present' : 'step did not run',
            ),
            'no_external_write' => $this->noExternalWrite($stepsById),
            default => $this->result($type, "Unknown assertion '{$type}'", false, 'unsupported assertion type'),
        };
    }

    /**
     * @param  array<string, mixed>  $assertion
     */
    private function outputEquals(array $assertion, ?object $stepRun): array
    {
        $path = (string) ($assertion['path'] ?? '');
        $expected = $assertion['value'] ?? null;
        $actual = $stepRun !== null ? data_get($stepRun->output, $path) : null;

        return $this->result(
            'output_equals',
            "{$assertion['step']}.{$path} == ".json_encode($expected),
            $stepRun !== null && $actual === $expected,
            'actual: '.json_encode($actual),
        );
    }

    /**
     * @param  array<string, mixed>  $assertion
     */
    private function outputMatches(array $assertion, ?object $stepRun): array
    {
        $path = (string) ($assertion['path'] ?? '');
        $pattern = (string) ($assertion['pattern'] ?? '');
        $actual = $stepRun !== null ? data_get($stepRun->output, $path) : null;
        $passed = $stepRun !== null && is_string($actual) && @preg_match('/'.str_replace('/', '\/', $pattern).'/', $actual) === 1;

        return $this->result(
            'output_matches',
            "{$assertion['step']}.{$path} ~ /{$pattern}/",
            $passed,
            'actual: '.json_encode($actual),
        );
    }

    /**
     * @param  array<string, WorkflowStepRun>  $stepsById
     */
    private function noExternalWrite(array $stepsById): array
    {
        $leaked = [];
        foreach ($stepsById as $stepRun) {
            $isExternal = in_array($stepRun->step_type, self::EXTERNAL_WRITE_TYPES, true);
            $simulated = ($stepRun->output['simulated'] ?? false) === true;
            if ($isExternal && $stepRun->status === 'completed' && ! $simulated) {
                $leaked[] = $stepRun->step_id;
            }
        }

        return $this->result(
            'no_external_write',
            'No external write was applied',
            $leaked === [],
            $leaked === [] ? 'all external calls simulated' : 'applied: '.implode(', ', $leaked),
        );
    }

    /**
     * @return array{type: string, label: string, passed: bool, detail: string}
     */
    private function result(string $type, string $label, bool $passed, string $detail): array
    {
        return ['type' => $type, 'label' => $label, 'passed' => $passed, 'detail' => $detail];
    }

    /**
     * The one-click default check set: every top-level step completes and no
     * external write is applied. The builder may pass a richer custom set.
     *
     * @param  array<string, mixed>  $workflow
     * @return list<array<string, mixed>>
     */
    public function defaultAssertions(array $workflow): array
    {
        $assertions = [];
        foreach ($workflow['steps'] ?? [] as $step) {
            if (isset($step['id'])) {
                $assertions[] = ['type' => 'step_status', 'step' => $step['id'], 'status' => 'completed'];
            }
        }
        $assertions[] = ['type' => 'no_external_write'];

        return $assertions;
    }
}
