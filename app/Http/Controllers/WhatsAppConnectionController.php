<?php

namespace App\Http\Controllers;

use App\Enums\ChannelStatus;
use App\Enums\ChannelType;
use App\Enums\Visibility;
use App\Http\Requests\WhatsApp\StoreWhatsAppConnectionRequest;
use App\Http\Requests\WhatsApp\UpdateWhatsAppConnectionRequest;
use App\Models\Agent;
use App\Models\AgentTeam;
use App\Models\Channel;
use App\Models\WhatsAppConnection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class WhatsAppConnectionController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', WhatsAppConnection::class);

        $channels = Channel::query()
            ->where('channel_type', ChannelType::WhatsApp)
            ->forAccountContext($request->user())
            ->with(['whatsAppConnection', 'agent:id,name', 'agentTeam:id,name'])
            ->latest()
            ->paginate(12);

        return Inertia::render('system/whatsapp/Index', [
            'channels' => $channels,
        ]);
    }

    public function create(Request $request): Response
    {
        $this->authorize('create', WhatsAppConnection::class);

        return Inertia::render('system/whatsapp/Create', [
            'agents' => Agent::forAccountContext($request->user())->get(['id', 'name', 'type', 'status']),
            'agentTeams' => AgentTeam::forAccountContext($request->user())->get(['id', 'name', 'status']),
        ]);
    }

    public function store(StoreWhatsAppConnectionRequest $request): RedirectResponse
    {
        $this->authorize('create', WhatsAppConnection::class);

        $user = $request->user();
        $visibility = $user->organization_id ? Visibility::Organization : Visibility::Private;
        $verifyToken = Str::random(64);

        $connection = DB::transaction(function () use ($request, $user, $visibility, $verifyToken) {
            $channel = Channel::create([
                'user_id' => $user->id,
                'organization_id' => $user->organization_id,
                'visibility' => $visibility,
                'channel_type' => ChannelType::WhatsApp,
                'name' => $request->validated('name'),
                'agent_id' => $request->validated('agent_id'),
                'agent_team_id' => $request->validated('agent_team_id'),
                'status' => ChannelStatus::Draft,
            ]);

            return WhatsAppConnection::create([
                'channel_id' => $channel->id,
                'display_phone_number' => $request->validated('display_phone_number'),
                'phone_number_id' => $request->validated('phone_number_id'),
                'business_account_id' => $request->validated('business_account_id'),
                'provider' => 'meta_cloud',
                'messaging_tier' => $request->validated('messaging_tier') ?? 'unverified',
                'webhook_verify_token' => $verifyToken,
                'auth_config' => array_filter([
                    'phone_number_id' => $request->validated('phone_number_id'),
                    'whatsapp_business_account_id' => $request->validated('business_account_id'),
                    'access_token' => $request->input('auth.access_token'),
                    'app_id' => $request->input('auth.app_id'),
                    'app_secret' => $request->input('auth.app_secret'),
                    'webhook_verify_token' => $verifyToken,
                    'graph_api_version' => $request->input('auth.graph_api_version') ?: 'v20.0',
                ], fn ($v) => $v !== null && $v !== ''),
            ]);
        });

        return to_route('whatsapp.connections.show', $connection);
    }

    public function show(WhatsAppConnection $whatsappConnection): Response
    {
        $this->authorize('view', $whatsappConnection);

        $whatsappConnection->load(['channel.agent', 'channel.agentTeam', 'templates']);

        $webhookUrl = route('webhooks.whatsapp.receive', $whatsappConnection);

        return Inertia::render('system/whatsapp/Show', [
            'connection' => array_merge($whatsappConnection->toArray(), [
                'masked_auth' => $whatsappConnection->maskedAuthConfig(),
            ]),
            'webhook_url' => $webhookUrl,
            'verify_token' => $whatsappConnection->webhook_verify_token,
        ]);
    }

    public function edit(Request $request, WhatsAppConnection $whatsappConnection): Response
    {
        $this->authorize('update', $whatsappConnection);

        $whatsappConnection->load(['channel']);

        return Inertia::render('system/whatsapp/Edit', [
            'connection' => array_merge($whatsappConnection->toArray(), [
                'masked_auth' => $whatsappConnection->maskedAuthConfig(),
            ]),
            'agents' => Agent::forAccountContext($request->user())->get(['id', 'name', 'type', 'status']),
            'agentTeams' => AgentTeam::forAccountContext($request->user())->get(['id', 'name', 'status']),
        ]);
    }

    public function update(UpdateWhatsAppConnectionRequest $request, WhatsAppConnection $whatsappConnection): RedirectResponse
    {
        $this->authorize('update', $whatsappConnection);

        $channel = $whatsappConnection->channel;

        $channelUpdates = array_filter([
            'name' => $request->validated('name') ?? null,
            'status' => $request->has('status') ? ChannelStatus::from($request->validated('status')) : null,
            'agent_id' => $request->has('agent_id') ? $request->validated('agent_id') : null,
            'agent_team_id' => $request->has('agent_team_id') ? $request->validated('agent_team_id') : null,
        ], fn ($value) => $value !== null);

        if (! empty($channelUpdates)) {
            $channel->update($channelUpdates);
        }

        $connectionUpdates = [];
        if ($request->filled('display_phone_number')) {
            $connectionUpdates['display_phone_number'] = $request->validated('display_phone_number');
        }
        if ($request->filled('messaging_tier')) {
            $connectionUpdates['messaging_tier'] = $request->validated('messaging_tier');
        }

        $auth = $whatsappConnection->auth_config ?? [];
        foreach (['access_token', 'app_id', 'app_secret', 'graph_api_version'] as $key) {
            $value = $request->input("auth.$key");
            if ($value !== null && $value !== '') {
                $auth[$key] = $value;
            }
        }
        $connectionUpdates['auth_config'] = $auth;

        $whatsappConnection->update($connectionUpdates);

        return to_route('whatsapp.connections.show', $whatsappConnection);
    }

    public function destroy(WhatsAppConnection $whatsappConnection): RedirectResponse
    {
        $this->authorize('delete', $whatsappConnection);

        DB::transaction(function () use ($whatsappConnection) {
            $channel = $whatsappConnection->channel;
            $whatsappConnection->delete();
            $channel?->delete();
        });

        return to_route('whatsapp.connections.index');
    }
}
