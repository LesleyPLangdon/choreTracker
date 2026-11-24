<?php
declare(strict_types=1);

namespace App;

use App\Approvals\ApprovalList;
use App\Chores\ChoreService;
use App\Chores\InstanceCreator;
use App\Chores\InstanceService;
use App\Money\Activity;
use App\Money\Buckets;
use App\Money\ChallengeService;
use App\Money\Currencies;
use App\Money\GoalService;
use App\Money\RewardService;
use App\Money\Summary;
use App\Money\Wallet;
use App\Rules\DayRules;
use App\Rules\Overrides;
use App\Rules\Settler;
use PDO;

/** Builds the app's services once, wired together. The API and the tests both get them from here. */
final class Services
{
    public readonly Ledger $ledger;
    public readonly ChoreService $chores;
    public readonly InstanceCreator $creator;
    public readonly Buckets $buckets;
    public readonly Currencies $currencies;
    public readonly DayRules $rules;
    public readonly InstanceService $instances;
    public readonly Wallet $wallet;
    public readonly Overrides $overrides;
    public readonly Settler $settler;
    public readonly GoalService $goals;
    public readonly RewardService $rewards;
    public readonly ChallengeService $challenges;
    public readonly Activity $activity;
    public readonly Summary $summary;
    public readonly ApprovalList $approvals;

    public function __construct(PDO $pdo, Clock $clock)
    {
        $this->ledger = new Ledger($pdo, $clock);
        $this->chores = new ChoreService($pdo, $clock);
        $this->creator = new InstanceCreator($pdo, $clock, $this->chores);
        $this->buckets = new Buckets($pdo, $clock);
        $this->currencies = new Currencies($pdo);
        $this->rules = new DayRules($pdo, $clock, $this->ledger, $this->chores, $this->buckets);
        $this->instances = new InstanceService($pdo, $clock, $this->ledger, $this->chores, $this->creator, $this->rules);
        $this->wallet = new Wallet($pdo, $clock, $this->ledger);
        $this->overrides = new Overrides($pdo, $clock, $this->ledger, $this->chores, $this->creator, $this->instances, $this->rules, $this->wallet);
        $this->settler = new Settler($pdo, $clock, $this->chores, $this->creator, $this->rules);
        $this->goals = new GoalService($pdo, $clock, $this->ledger);
        $this->rewards = new RewardService($pdo, $clock, $this->ledger, $this->goals);
        $this->challenges = new ChallengeService($pdo, $clock, $this->chores);
        $this->activity = new Activity($pdo, $clock, $this->chores);
        $this->summary = new Summary($pdo, $this->ledger, $this->currencies, $this->buckets, $this->goals, $this->rewards);
        $this->approvals = new ApprovalList($this->instances, $this->goals, $this->rewards, $this->challenges);
    }
}
