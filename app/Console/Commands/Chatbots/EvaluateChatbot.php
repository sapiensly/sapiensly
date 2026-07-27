<?php

namespace App\Console\Commands\Chatbots;

use App\Models\Agent;
use App\Models\Chatbot;
use App\Services\Chatbots\ChatbotEvaluator;
use App\Support\Chatbots\Evaluation\EvalCase;
use App\Support\Chatbots\Evaluation\EvalOutcome;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * Run a bot against a set of questions and report whether it answered from the
 * material or made things up.
 *
 * The point is the BEFORE and AFTER. Change the grounding instructions, run this
 * on the same set, compare the two numbers — that is the difference between
 * "this prompt feels better" and knowing. Nothing in the test suite can do it,
 * because a real answer costs money and is not the same twice; before this,
 * nothing in the product could do it at all.
 */
#[Signature('chatbot:evaluate
    {chatbot : Chatbot id or name}
    {--set= : Path to a JSON eval set (defaults to the bundled grounding set)}
    {--show-answers : Print every answer, not just the failures}')]
#[Description('Ask a chatbot a set of questions and report whether it stayed grounded')]
class EvaluateChatbot extends Command
{
    public function handle(ChatbotEvaluator $evaluator): int
    {
        $chatbot = $this->resolveChatbot();
        if ($chatbot === null) {
            $this->error('No chatbot matched that id or name.');

            return self::FAILURE;
        }

        $agent = $chatbot->botFlow?->rosterAgents()[0] ?? null;
        if (! $agent instanceof Agent) {
            $this->error("«{$chatbot->name}» has no agent on its flow, so there is nothing to evaluate.");

            return self::FAILURE;
        }

        $cases = $this->loadCases();
        if ($cases === []) {
            $this->error('That eval set has no usable cases.');

            return self::FAILURE;
        }

        $this->line("Asking «{$chatbot->name}» ".count($cases).' question(s) via '.($agent->model ?: 'its default model').'…');
        $this->newLine();

        return $this->report($evaluator->run($agent, $cases, $chatbot->user));
    }

    private function resolveChatbot(): ?Chatbot
    {
        $needle = (string) $this->argument('chatbot');

        return Chatbot::query()
            ->where('id', $needle)
            ->orWhere('name', $needle)
            ->first();
    }

    /**
     * @return list<EvalCase>
     */
    private function loadCases(): array
    {
        $path = (string) ($this->option('set') ?: database_path('eval/chatbot-grounding.json'));

        if (! File::exists($path)) {
            $this->error("No eval set at {$path}.");

            return [];
        }

        $decoded = json_decode(File::get($path), true);
        if (! is_array($decoded)) {
            return [];
        }

        return array_values(array_map(
            fn (array $case) => EvalCase::fromArray($case),
            array_filter($decoded, 'is_array'),
        ));
    }

    /**
     * @param  list<EvalOutcome>  $outcomes
     */
    private function report(array $outcomes): int
    {
        $passed = array_filter($outcomes, fn (EvalOutcome $o) => $o->passed());

        foreach ($outcomes as $outcome) {
            if ($outcome->passed() && ! $this->option('show-answers')) {
                continue;
            }

            $mark = $outcome->passed() ? '<info>ok</info>  ' : '<error>fail</error>';
            $this->line("{$mark}  {$outcome->case->id} — {$outcome->case->question}");

            foreach ($outcome->failures as $failure) {
                $this->line("        · {$failure}");
            }

            $this->line('        answer: '.str_replace("\n", ' ', mb_substr($outcome->answer, 0, 240)));
            $this->newLine();
        }

        $total = count($outcomes);
        $rate = $total > 0 ? (int) round(count($passed) / $total * 100) : 0;

        $this->line("Grounded on {$rate}% of the set (".count($passed)."/{$total}).");

        // A non-zero exit makes this usable as a gate later, once a team has
        // agreed what number is good enough. Until then it is a report.
        return count($passed) === $total ? self::SUCCESS : self::FAILURE;
    }
}
