<?php

declare(strict_types=1);

namespace App\Modules\DistributorOnboarding\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Access\Domain\Authorization\PermissionCode;
use App\Modules\DistributorOnboarding\Application\Applications\ApplicationQueryService;
use App\Modules\DistributorOnboarding\Application\Applications\CreateApplication;
use App\Modules\DistributorOnboarding\Application\Applications\CreateApplicationCommand;
use App\Modules\DistributorOnboarding\Application\Applications\SubmitApplication;
use App\Modules\DistributorOnboarding\Application\Applications\SubmitApplicationCommand;
use App\Modules\DistributorOnboarding\Application\Applications\UpdateCapture;
use App\Modules\DistributorOnboarding\Application\Applications\UpdateCaptureCommand;
use App\Modules\DistributorOnboarding\Application\Authorizations\RecordManagerDecision;
use App\Modules\DistributorOnboarding\Application\Authorizations\RecordManagerDecisionCommand;
use App\Modules\DistributorOnboarding\Application\Corrections\CompleteCorrections;
use App\Modules\DistributorOnboarding\Application\Corrections\CompleteCorrectionsCommand;
use App\Modules\DistributorOnboarding\Application\Corrections\RecordCorrection;
use App\Modules\DistributorOnboarding\Application\Corrections\RecordCorrectionCommand;
use App\Modules\DistributorOnboarding\Application\Evaluations\RecordCoordinatorDecision;
use App\Modules\DistributorOnboarding\Application\Evaluations\RecordCoordinatorDecisionCommand;
use App\Modules\DistributorOnboarding\Application\Reviews\RequestDocumentCorrection;
use App\Modules\DistributorOnboarding\Application\Reviews\RequestDocumentCorrectionCommand;
use App\Modules\DistributorOnboarding\Application\Security\ActorContext;
use App\Modules\DistributorOnboarding\Application\Security\ActorContextFactory;
use App\Modules\DistributorOnboarding\Application\Support\OnboardingAuthorizer;
use App\Modules\DistributorOnboarding\Application\VerificationAssignments\AssignVerifier;
use App\Modules\DistributorOnboarding\Application\VerificationAssignments\AssignVerifierCommand;
use App\Modules\DistributorOnboarding\Application\Visits\CompleteVisit;
use App\Modules\DistributorOnboarding\Application\Visits\CompleteVisitCommand;
use App\Modules\DistributorOnboarding\Application\Visits\RecordDifference;
use App\Modules\DistributorOnboarding\Application\Visits\RecordDifferenceCommand;
use App\Modules\DistributorOnboarding\Application\Visits\StartVisit;
use App\Modules\DistributorOnboarding\Application\Visits\StartVisitCommand;
use App\Modules\DistributorOnboarding\Domain\Decisions\CoordinatorDecision;
use App\Modules\DistributorOnboarding\Domain\Decisions\ManagerDecision;
use App\Modules\DistributorOnboarding\Domain\Exceptions\OnboardingDomainException;
use App\Modules\DistributorOnboarding\Domain\Expedients\ExpedientSection;
use App\Modules\DistributorOnboarding\Domain\Verification\VisitResult;
use App\Modules\DistributorOnboarding\Persistence\Models\ApplicationAudit;
use App\Modules\DistributorOnboarding\Persistence\Models\DistributorApplication;
use App\Modules\DistributorOnboarding\Presentation\Http\Requests\ApplicationIndexRequest;
use App\Modules\DistributorOnboarding\Presentation\Http\Requests\AssignVerifierRequest;
use App\Modules\DistributorOnboarding\Presentation\Http\Requests\CompleteVisitRequest;
use App\Modules\DistributorOnboarding\Presentation\Http\Requests\CoordinatorDecisionRequest;
use App\Modules\DistributorOnboarding\Presentation\Http\Requests\ManagerDecisionRequest;
use App\Modules\DistributorOnboarding\Presentation\Http\Requests\ReasonActionRequest;
use App\Modules\DistributorOnboarding\Presentation\Http\Requests\RecordCorrectionRequest;
use App\Modules\DistributorOnboarding\Presentation\Http\Requests\RecordDifferenceRequest;
use App\Modules\DistributorOnboarding\Presentation\Http\Requests\StoreApplicationRequest;
use App\Modules\DistributorOnboarding\Presentation\Http\Requests\UpdateCaptureRequest;
use App\Modules\DistributorOnboarding\Presentation\Http\Requests\VersionedActionRequest;
use App\Modules\DistributorOnboarding\Presentation\Http\Resources\ApplicationPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/** Controlador delgado para las operaciones explícitas de M04. */
final class DistributorApplicationController extends Controller
{
    public function __construct(
        private readonly ActorContextFactory $contexts,
        private readonly ApplicationQueryService $queries,
        private readonly ApplicationPresenter $presenter,
        private readonly OnboardingAuthorizer $authorizer,
    ) {}

    public function index(ApplicationIndexRequest $request): JsonResponse
    {
        Gate::authorize('viewAny', DistributorApplication::class);
        $actor = $this->actor($request);
        $paginator = $this->queries->paginate($actor, $request->validated());

        return response()->json([
            'data' => collect($paginator->items())
                ->map(fn ($application): array => $this->presenter->summary($application, $actor))
                ->all(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
                'request_id' => $request->attributes->get('request_id'),
            ],
        ]);
    }

    public function store(StoreApplicationRequest $request, CreateApplication $handler): JsonResponse
    {
        $application = $handler->execute(new CreateApplicationCommand(
            contactEmail: (string) $request->validated('contact_email'),
            accountName: (string) $request->validated('account_name'),
            actor: $this->actor($request),
            metadata: $request->metadata(),
        ));

        return $this->applicationResponse($request, $application->public_id, 201);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $actor = $this->actor($request);
        $application = $this->queries->find($actor, $id);
        Gate::authorize('view', $application);

        return response()->json([
            'data' => $this->presenter->detail($application, $actor),
            'meta' => ['request_id' => $request->attributes->get('request_id')],
        ]);
    }

    public function update(UpdateCaptureRequest $request, string $id, UpdateCapture $handler): JsonResponse
    {
        $handler->execute(new UpdateCaptureCommand(
            applicationPublicId: $id,
            lockVersion: (int) $request->validated('lock_version'),
            contactEmail: $request->has('contact_email') ? (string) $request->validated('contact_email') : null,
            accountName: $request->has('account_name') ? (string) $request->validated('account_name') : null,
            personalData: (array) $request->validated('personal_data', []),
            actor: $this->actor($request),
            metadata: $request->metadata(),
        ));

        return $this->applicationResponse($request, $id);
    }

    public function submit(VersionedActionRequest $request, string $id, SubmitApplication $handler): JsonResponse
    {
        $handler->execute(new SubmitApplicationCommand(
            $id,
            (int) $request->validated('lock_version'),
            $this->actor($request),
            $request->metadata(),
        ));

        return $this->applicationResponse($request, $id);
    }

    public function requestDocumentCorrection(
        ReasonActionRequest $request,
        string $id,
        RequestDocumentCorrection $handler,
    ): JsonResponse {
        $handler->execute(new RequestDocumentCorrectionCommand(
            $id,
            (int) $request->validated('lock_version'),
            (string) $request->validated('reason'),
            $this->actor($request),
            $request->metadata(),
        ));

        return $this->applicationResponse($request, $id);
    }

    public function assignVerifier(
        AssignVerifierRequest $request,
        string $id,
        AssignVerifier $handler,
    ): JsonResponse {
        $handler->execute(new AssignVerifierCommand(
            $id,
            (string) $request->validated('verifier_id'),
            (int) $request->validated('lock_version'),
            $this->actor($request),
            $request->metadata(),
        ));

        return $this->applicationResponse($request, $id);
    }

    public function startVisit(
        VersionedActionRequest $request,
        string $id,
        StartVisit $handler,
    ): JsonResponse {
        $visit = $handler->execute(new StartVisitCommand(
            $id,
            (int) $request->validated('lock_version'),
            null,
            $this->actor($request),
            $request->metadata(),
        ));

        return response()->json([
            'data' => ['id' => $visit->public_id, 'started_at' => $visit->started_at->toISOString()],
            'meta' => ['request_id' => $request->attributes->get('request_id')],
        ], 201);
    }

    public function showVisit(Request $request, string $id, string $visitId): JsonResponse
    {
        $actor = $this->actor($request);
        $application = $this->queries->find($actor, $id);
        Gate::authorize('view', $application);
        if ($application->visit === null || $application->visit->public_id !== $visitId) {
            throw OnboardingDomainException::scopeDenied();
        }

        return response()->json([
            'data' => $this->presenter->detail($application, $actor)['visit'],
            'meta' => ['request_id' => $request->attributes->get('request_id')],
        ]);
    }

    public function recordDifference(
        RecordDifferenceRequest $request,
        string $id,
        string $visitId,
        RecordDifference $handler,
    ): JsonResponse {
        $difference = $handler->execute(new RecordDifferenceCommand(
            applicationPublicId: $id,
            visitPublicId: $visitId,
            lockVersion: (int) $request->validated('lock_version'),
            section: ExpedientSection::from((string) $request->validated('section')),
            fieldPath: (string) $request->validated('field_path'),
            declaredValue: (string) $request->validated('declared_value'),
            observedValue: (string) $request->validated('observed_value'),
            description: (string) $request->validated('description'),
            classificationCode: (string) $request->validated('classification_code'),
            evidenceMediaId: $request->validated('evidence_media_id'),
            actor: $this->actor($request),
            metadata: $request->metadata(),
        ));

        return response()->json([
            'data' => ['id' => $difference->public_id],
            'meta' => ['request_id' => $request->attributes->get('request_id')],
        ], 201);
    }

    public function completeVisit(
        CompleteVisitRequest $request,
        string $id,
        string $visitId,
        CompleteVisit $handler,
    ): JsonResponse {
        $handler->execute(new CompleteVisitCommand(
            $id,
            $visitId,
            (int) $request->validated('lock_version'),
            VisitResult::from((string) $request->validated('result')),
            (string) $request->validated('observations'),
            $this->actor($request),
            $request->metadata(),
        ));

        return $this->applicationResponse($request, $id);
    }

    public function recordCorrection(
        RecordCorrectionRequest $request,
        string $id,
        RecordCorrection $handler,
    ): JsonResponse {
        $handler->execute(new RecordCorrectionCommand(
            applicationPublicId: $id,
            lockVersion: (int) $request->validated('lock_version'),
            section: ExpedientSection::from((string) $request->validated('section')),
            fieldPath: (string) $request->validated('field_path'),
            expectedOriginalValue: (string) $request->validated('expected_original_value'),
            correctedValue: (string) $request->validated('corrected_value'),
            reason: (string) $request->validated('reason'),
            differencePublicId: $request->validated('difference_id'),
            actor: $this->actor($request),
            metadata: $request->metadata(),
        ));

        return $this->applicationResponse($request, $id);
    }

    public function completeCorrections(
        VersionedActionRequest $request,
        string $id,
        CompleteCorrections $handler,
    ): JsonResponse {
        $handler->execute(new CompleteCorrectionsCommand(
            $id,
            (int) $request->validated('lock_version'),
            $this->actor($request),
            $request->metadata(),
        ));

        return $this->applicationResponse($request, $id);
    }

    public function coordinatorDecision(
        CoordinatorDecisionRequest $request,
        string $id,
        RecordCoordinatorDecision $handler,
    ): JsonResponse {
        $handler->execute(new RecordCoordinatorDecisionCommand(
            $id,
            (int) $request->validated('lock_version'),
            CoordinatorDecision::from((string) $request->validated('decision')),
            (string) $request->validated('reason'),
            $this->actor($request),
            $request->metadata(),
        ));

        return $this->applicationResponse($request, $id);
    }

    public function managerDecision(
        ManagerDecisionRequest $request,
        string $id,
        RecordManagerDecision $handler,
    ): JsonResponse {
        $handler->execute(new RecordManagerDecisionCommand(
            applicationPublicId: $id,
            lockVersion: (int) $request->validated('lock_version'),
            decision: ManagerDecision::from((string) $request->validated('decision')),
            initialCreditLine: $request->validated('initial_credit_line'),
            reason: (string) $request->validated('reason'),
            reauthenticationToken: $request->validated('reauthentication_token'),
            actor: $this->actor($request),
            metadata: $request->metadata(),
        ));

        return $this->applicationResponse($request, $id);
    }

    public function history(Request $request, string $id): JsonResponse
    {
        $actor = $this->actor($request);
        $this->authorizer->assertPermission($actor, PermissionCode::ONBOARDING_HISTORY_VIEW);
        $application = $this->queries->find($actor, $id);
        Gate::authorize('view', $application);
        $history = $application->audits()
            ->with('requester:id,public_id,name')
            ->orderBy('occurred_at')
            ->orderBy('id')
            ->get();

        return response()->json([
            'data' => $history->map(fn (ApplicationAudit $entry): array => [
                'id' => $entry->public_id,
                'action' => $entry->event_type,
                'previous_status' => $entry->previous_status?->value,
                'new_status' => $entry->new_status?->value,
                'actor' => $entry->requester === null ? null : [
                    'id' => $entry->requester->public_id,
                    'name' => $entry->requester->name,
                    'role' => $entry->actor_role,
                ],
                'entity' => [
                    'type' => $entry->entity_type,
                    'id' => $entry->entity_public_id,
                ],
                'reason' => $entry->reason,
                'result' => $entry->result,
                'application_version' => $entry->application_version,
                'occurred_at' => $entry->occurred_at->toISOString(),
            ])->all(),
            'meta' => ['request_id' => $request->attributes->get('request_id')],
        ]);
    }

    public function activation(Request $request, string $id): JsonResponse
    {
        $actor = $this->actor($request);
        $application = $this->queries->find($actor, $id);
        Gate::authorize('view', $application);

        return response()->json([
            'data' => $application->activation === null ? null : $this->presenter->activation($application),
            'meta' => ['request_id' => $request->attributes->get('request_id')],
        ]);
    }

    private function applicationResponse(Request $request, string $id, int $status = 200): JsonResponse
    {
        $actor = $this->actor($request);
        $application = $this->queries->find($actor, $id);
        Gate::authorize('view', $application);

        return response()->json([
            'data' => $this->presenter->detail($application, $actor),
            'meta' => ['request_id' => $request->attributes->get('request_id')],
        ], $status);
    }

    private function actor(Request $request): ActorContext
    {
        $user = $request->user();
        if (! $user instanceof User) {
            throw OnboardingDomainException::authorizationDenied();
        }

        return $this->contexts->fromUser($user);
    }
}
