<?php
declare(strict_types=1);

namespace App;

use App\Chores\ChoreService;
use App\Chores\InstanceCreator;
use App\Chores\InstanceService;
use App\Controllers\AccountController;
use App\Controllers\ChoreController;
use App\Controllers\DayController;
use App\Controllers\HouseholdController;
use App\Controllers\InviteController;
use App\Controllers\KidAuthController;
use App\Controllers\KidController;
use App\Controllers\RulesController;
use App\Controllers\StatusController;
use App\Http\ApiException;
use App\Http\Request;
use App\Http\Response;
use App\Http\Router;
use App\Rules\DayRules;
use App\Rules\Overrides;
use App\Rules\Settler;
use PDO;

/** Wires everything together. `handle()` turns a Request into a Response (no HTTP needed, so tests call it directly). */
final class App
{
    public readonly Auth $auth;
    public readonly Settler $settler;
    private Router $router;

    public function __construct(public readonly PDO $pdo, public readonly Config $config, public readonly Clock $clock)
    {
        $this->auth = new Auth($pdo, $clock);
        $this->router = new Router();
        $this->routes();
        // Every authenticated request first settles any household days that have ended (see Settler).
        $this->auth->onAuthenticated(function (int $householdId): void {
            try {
                $this->settler->settleHousehold($householdId);
            } catch (\Throwable $e) {
                error_log('Chore Tracker settle error: ' . $e); // never block the request itself
            }
        });
    }

    /** Builds the app from config.php, honouring `simulated_now` on test sites only. */
    public static function fromConfig(Config $config): self
    {
        $clock = new Clock();
        $sim = $config->get('simulated_now');
        if ($config->isTestSite() && is_string($sim) && $sim !== '') {
            $clock->set($sim);
        }
        return new self(Database::connect($config), $config, $clock);
    }

    public function handle(Request $request): Response
    {
        try {
            $cors = $this->corsHeaders($request);
            if ($request->method === 'OPTIONS') {
                return new Response(204, [], $cors);
            }
            $response = $this->router->dispatch($request);
            return new Response($response->status, $response->data, $cors + $response->headers);
        } catch (ApiException $e) {
            return new Response($e->status, ['error' => $e->getMessage()] + $e->extra, $this->corsHeaders($request));
        } catch (\Throwable $e) {
            error_log('Chore Tracker error: ' . $e);
            return new Response(500, ['error' => 'Something went wrong on our side.'], $this->corsHeaders($request));
        }
    }

    private function corsHeaders(Request $request): array
    {
        $origin = $request->header('origin');
        $allowed = $this->config->get('cors_allowed_origins', []);
        if ($origin !== null && is_array($allowed) && in_array($origin, $allowed, true)) {
            return [
                'Access-Control-Allow-Origin' => $origin,
                'Access-Control-Allow-Headers' => 'Authorization, Content-Type',
                'Access-Control-Allow-Methods' => 'GET, POST, PUT, DELETE, OPTIONS',
                'Vary' => 'Origin',
            ];
        }
        return [];
    }

    private function routes(): void
    {
        $args = [$this->pdo, $this->config, $this->clock, $this->auth];
        $account = new AccountController(...$args);
        $household = new HouseholdController(...$args);
        $invites = new InviteController(...$args);
        $kids = new KidController(...$args);
        $kidAuth = new KidAuthController(...$args);
        $choreService = new ChoreService($this->pdo, $this->clock);
        $ledger = new Ledger($this->pdo, $this->clock);
        $creator = new InstanceCreator($this->pdo, $this->clock, $choreService);
        $rules = new DayRules($this->pdo, $this->clock, $ledger, $choreService);
        $instanceService = new InstanceService($this->pdo, $this->clock, $ledger, $choreService, $creator, $rules);
        $overrides = new Overrides($this->pdo, $this->clock, $ledger, $choreService, $creator, $instanceService, $rules);
        $this->settler = new Settler($this->pdo, $this->clock, $choreService, $creator, $rules);
        $choreCtl = new ChoreController(...$args, ...[$choreService, $instanceService]);
        $dayCtl = new DayController(...$args, ...[$instanceService]);
        $rulesCtl = new RulesController(...$args, ...[$overrides]);
        $statusCtl = new StatusController(...$args);
        $r = $this->router;

        $r->add('POST', '/register', [$account, 'register']);
        $r->add('POST', '/login', [$account, 'login']);
        $r->add('POST', '/logout', [$account, 'logout']);
        $r->add('GET', '/me', [$account, 'me']);

        $r->add('GET', '/household', [$household, 'show']);
        $r->add('PUT', '/household', [$household, 'update']);

        $r->add('GET', '/invites', [$invites, 'index']);
        $r->add('POST', '/invites', [$invites, 'create']);
        $r->add('DELETE', '/invites/{id}', [$invites, 'revoke']);
        $r->add('GET', '/invite/{token}', [$invites, 'preview']);
        $r->add('POST', '/invite/{token}/accept', [$invites, 'accept']);

        $r->add('GET', '/kids', [$kids, 'index']);
        $r->add('POST', '/kids', [$kids, 'create']);
        $r->add('PUT', '/kids/{id}', [$kids, 'update']);
        $r->add('POST', '/kids/{id}/active', [$kids, 'setActive']);
        $r->add('POST', '/kids/{id}/pin', [$kids, 'setPin']);
        $r->add('POST', '/kids/{id}/link/reset', [$kids, 'resetLink']);
        $r->add('POST', '/kids/{id}/unlock', [$kids, 'unlock']);

        $r->add('GET', '/chores', [$choreCtl, 'index']);
        $r->add('POST', '/chores', [$choreCtl, 'create']);
        $r->add('PUT', '/chores/{id}', [$choreCtl, 'update']);
        $r->add('DELETE', '/chores/{id}', [$choreCtl, 'remove']);

        $r->add('GET', '/kids/{id}/today', [$dayCtl, 'parentKidToday']);
        $r->add('POST', '/instances/{id}/approve', [$dayCtl, 'approve']);
        $r->add('POST', '/instances/{id}/send-back', [$dayCtl, 'sendBack']);
        $r->add('POST', '/instances/{id}/mark-done', [$dayCtl, 'markDone']);
        $r->add('GET', '/approvals', [$dayCtl, 'approvals']);
        $r->add('GET', '/approvals/count', [$dayCtl, 'approvalCount']);

        $r->add('GET', '/clock', [$statusCtl, 'clock']);
        $r->add('GET', '/kids/{id}/history', [$rulesCtl, 'history']);
        $r->add('POST', '/kids/{id}/back-fill', [$rulesCtl, 'backFill']);
        $r->add('POST', '/kids/{id}/days/{date}/unlock', [$rulesCtl, 'unlockDay']);
        $r->add('POST', '/kids/{id}/days/{date}/forfeit', [$rulesCtl, 'forfeitDay']);
        $r->add('POST', '/kids/{id}/adjust', [$rulesCtl, 'adjust']);
        $r->add('POST', '/instances/{id}/undo-approval', [$rulesCtl, 'undoApproval']);
        $r->add('POST', '/instances/{id}/clear-late', [$rulesCtl, 'clearLate']);
        $r->add('POST', '/kid/days/{date}/ack', [$rulesCtl, 'ackNotice']);

        $r->add('GET', '/kid/today', [$dayCtl, 'kidToday']);
        $r->add('POST', '/kid/instances/{id}/done', [$dayCtl, 'kidDone']);
        $r->add('POST', '/kid/instances/{id}/undo', [$dayCtl, 'kidUndo']);

        $r->add('GET', '/kid/link/{token}', [$kidAuth, 'preview']);
        $r->add('POST', '/kid/login', [$kidAuth, 'login']);
        $r->add('GET', '/kid/me', [$kidAuth, 'me']);
        $r->add('POST', '/kid/logout', [$kidAuth, 'logout']);
    }
}
