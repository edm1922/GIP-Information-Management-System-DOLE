<x-app-layout>
    @php
        $pageUnreadCount = $notifications->getCollection()->filter(fn($n) => is_null($n->read_at))->count();
        $totalUnreadCount = auth()->user()->unreadNotifications()->count();
    @endphp

    @push('styles')
        <style>
            .notif-list {
                border-radius: 16px;
                border: 1px solid #dce6f7;
                overflow: hidden;
                background: #fff;
            }

            .notif-item {
                border: 0;
                border-bottom: 1px solid #edf2fa;
                padding: 0.7rem 0.8rem;
                transition: background-color 0.2s ease;
            }

            .notif-item:hover {
                background: #f9fbff;
            }

            .notif-item:last-child {
                border-bottom: 0;
            }

            .notif-item-unread {
                background: linear-gradient(90deg, #eef5ff 0%, #f8fbff 100%);
            }

            .notif-dot {
                width: 8px;
                height: 8px;
                border-radius: 50%;
                margin-top: 0.4rem;
                flex-shrink: 0;
                background: #9bb0cc;
            }

            .notif-dot-unread {
                background: #2f6fed;
                box-shadow: 0 0 0 4px rgba(47, 111, 237, 0.16);
            }

            .notif-chip {
                font-size: 0.64rem;
                font-weight: 600;
                padding: 0.18rem 0.45rem;
                border-radius: 999px;
                border: 1px solid #dbe3f1;
                color: #435470;
                background: #f8faff;
            }

            .notif-actions .btn {
                min-height: 34px;
                min-width: 34px;
                font-size: 0.8rem;
                padding: 0.3rem 0.55rem;
            }

            .notif-actions form {
                margin: 0;
            }

            @media (max-width: 767.98px) {
                .notif-item {
                    padding: 0.7rem;
                }

                .notif-actions {
                    width: 100%;
                }

                .notif-actions .btn,
                .notif-actions form {
                    width: 100%;
                }
            }

            @media (prefers-reduced-motion: reduce) {
                .notif-item {
                    transition: none;
                }
            }
        </style>
    @endpush

    <div class="d-flex justify-content-between align-items-center mb-4 mt-2">
        <div>
            <h1 id="notifications-heading" class="h5 mb-1 fw-bold text-dark">Notifications</h1>
            <p class="mb-0 text-muted small">Document expiry reminders and alerts.</p>
        </div>
        @if ($notifications->count() > 0)
            <form method="POST" action="{{ route('notifications.mark-all-read') }}" class="d-flex align-items-center">
                @csrf
                <button type="submit" class="btn btn-sm btn-outline-secondary">
                    <i class="fas fa-check-double me-1" aria-hidden="true"></i>Mark all as read
                </button>
            </form>
        @endif
    </div>

    @if (session('success'))
        <div class="alert-flash alert-flash--success mb-4" role="status" aria-live="polite">
            <i class="fas fa-check-circle me-2"></i>{{ session('success') }}
        </div>
    @endif

    <section class="notif-list" aria-label="Notification list">
        @forelse ($notifications as $notification)
            @php
                $data = $notification->data;
                $isUnread = is_null($notification->read_at);

                // Only render links that resolve to a same-origin path. Route-based
                // URLs are absolute, so same-origin ones are normalised back to a
                // path; anything else (javascript:, data:, protocol-relative,
                // foreign host) is not rendered as an anchor at all.
                $isInternalPath = static function ($path): bool {
                    return is_string($path)
                        && str_starts_with($path, '/')
                        && !str_starts_with($path, '//')
                        && !str_starts_with($path, '/\\');
                };

                $candidate = isset($data['url_route'])
                    ? route($data['url_route'], $data['url_params'] ?? [])
                    : ($data['url'] ?? null);

                // Smart repair for old absolute URLs missing the project subdirectory.
                // The legacy route may no longer exist: never call route() for it unless
                // it is registered, otherwise a future legacy row would 500 the page.
                if (!isset($data['url_route']) && is_string($candidate) && str_contains($candidate, 'department-documents')) {
                    if (Route::has('department-documents.index')) {
                        $query = parse_url($candidate, PHP_URL_QUERY);
                        $candidate = route('department-documents.index') . ($query ? '?' . $query : '');
                    } else {
                        $candidate = null;
                    }
                }

                $linkUrl = null;
                if (is_string($candidate) && $candidate !== '') {
                    if ($isInternalPath($candidate)) {
                        $linkUrl = $candidate;
                    } else {
                        $parts = parse_url($candidate);
                        $origin = parse_url(request()->getSchemeAndHttpHost()) ?: [];

                        $sameOrigin = is_array($parts)
                            && isset($parts['scheme'], $parts['host'], $parts['path'])
                            && strtolower($parts['scheme']) === strtolower((string) ($origin['scheme'] ?? ''))
                            && strtolower($parts['host']) === strtolower((string) ($origin['host'] ?? ''))
                            && ($parts['port'] ?? null) === ($origin['port'] ?? null);

                        if ($sameOrigin && $isInternalPath($parts['path'])) {
                            $linkUrl = $parts['path'] . (isset($parts['query']) ? '?' . $parts['query'] : '');
                        }
                    }
                }

                $isExpired = (bool) ($data['is_expired'] ?? false);
            @endphp

            <article class="notif-item {{ $isUnread ? 'notif-item-unread' : '' }}">
                <div class="d-flex gap-3 align-items-start">
                    <div class="notif-dot {{ $isUnread ? 'notif-dot-unread' : '' }}" aria-hidden="true"></div>

                    <div class="flex-grow-1">
                        <div class="d-flex flex-wrap align-items-center gap-2 mb-1">
                            <span class="notif-chip">{{ $isExpired ? 'Expired' : 'Expiry Reminder' }}</span>
                            @if (!empty($data['department_name']))
                                <span class="notif-chip">{{ $data['department_name'] }}</span>
                            @endif
                            @if ($isUnread)
                                <span class="badge text-bg-primary">New</span>
                            @endif
                        </div>

                        <h2 class="mb-1 fw-semibold text-dark" style="font-size: 0.95rem;">
                            {{ $data['message'] ?? 'Notification' }}
                        </h2>
                        <div class="text-muted" style="font-size: 0.78rem;">
                            <i class="far fa-clock me-1"
                                aria-hidden="true"></i>{{ $notification->created_at?->diffForHumans() }}
                            @if (!empty($data['expiry_date']))
                                <span class="ms-2"><i class="far fa-calendar-alt me-1" aria-hidden="true"></i>Expiry:
                                    {{ \Carbon\Carbon::parse($data['expiry_date'])->format('M d, Y') }}</span>
                            @endif
                        </div>
                    </div>

                    <div class="notif-actions d-flex flex-column flex-md-row align-items-stretch gap-2">
                        @if ($linkUrl !== null)
                            <a href="{{ $linkUrl }}" class="btn btn-outline-danger">
                                <i class="fas fa-arrow-up-right-from-square me-1" aria-hidden="true"></i>Open
                            </a>
                        @else
                            <span class="btn btn-outline-secondary disabled" aria-disabled="true" title="This notification has no internal destination">
                                <i class="fas fa-arrow-up-right-from-square me-1" aria-hidden="true"></i>Open
                            </span>
                        @endif
                        @if ($isUnread)
                            <form method="POST"
                                action="{{ route('notifications.mark-as-read', ['id' => $notification->id, 'redirect' => 'back']) }}">
                                @csrf
                                <button type="submit" class="btn btn-danger">
                                    <i class="fas fa-check me-1" aria-hidden="true"></i>Mark as read
                                </button>
                            </form>
                        @endif
                    </div>
                </div>
            </article>
        @empty
            <div class="py-5 text-center px-3">
                <div class="mb-2"><i class="far fa-bell-slash fa-2x text-muted" aria-hidden="true"></i></div>
                <h2 class="h6 mb-1 text-dark">No notifications yet</h2>
                <p class="text-muted mb-0">You will see expiry reminders and document alerts here.</p>
            </div>
        @endforelse
    </section>

    <div class="mt-3">
        {{ $notifications->links() }}
    </div>
</x-app-layout>