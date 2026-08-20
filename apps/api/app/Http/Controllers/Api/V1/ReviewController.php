<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Reviews\Models\Review;
use App\Http\Controllers\Controller;
use App\Http\Requests\ResolveReviewRequest;
use App\Http\Resources\ReviewResource;
use App\Services\Reviews\ReviewService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ReviewController extends Controller
{
    public function __construct(private readonly ReviewService $reviews) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Review::class);

        $query = Review::query()
            ->with(['claim.workOrder', 'decision', 'assignee', 'resolver'])
            ->when($request->boolean('pending_only', true), fn ($q) => $q->pending())
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->latest();

        return ReviewResource::collection($query->paginate($request->integer('per_page', 25)));
    }

    public function show(Review $review): ReviewResource
    {
        $this->authorize('view', $review);

        return new ReviewResource(
            $review->load(['claim.workOrder', 'claim.latestRun.evidence', 'claim.latestRun.traceEvents', 'decision', 'assignee', 'resolver'])
        );
    }

    public function assign(Review $review, Request $request): ReviewResource
    {
        $this->authorize('resolve', $review);

        return new ReviewResource(
            $this->reviews->claimForReview($review, $request->user())->load(['claim', 'decision', 'assignee'])
        );
    }

    /** Human decision. Never overwrites the automated one — it supersedes it. */
    public function resolve(ResolveReviewRequest $request, Review $review): ReviewResource
    {
        $this->authorize('resolve', $review);

        $resolved = $this->reviews->resolve(
            $review,
            $request->user(),
            $request->string('outcome')->toString(),
            $request->input('override_state'),
            $request->input('notes'),
        );

        return new ReviewResource($resolved->load(['claim.workOrder', 'decision', 'resolver']));
    }
}
