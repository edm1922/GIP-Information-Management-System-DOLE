{{-- ── Sidebar Backdrop ── --}}
<div class="sidebar-backdrop" id="sidebar-backdrop"></div>

{{-- ── Sidebar Drawer ── --}}
<div class="sidebar-drawer d-flex flex-column" id="sidebar-drawer" style="overflow-y: hidden;">

    {{-- User Info --}}
    <div class="sidebar-user-info">
        <div class="sidebar-user-avatar">
            <i class="fas fa-user-circle"></i>
        </div>
        <div class="sidebar-user-details">
            <div class="sidebar-user-name">{{ Auth::user()->name }}</div>
            <div class="sidebar-user-role">
                <span class="badge bg-{{ Auth::user()->role === 'admin' ? 'danger' : (Auth::user()->role === 'peso_manager' ? 'warning' : 'info') }}">
                    {{ ucfirst(Auth::user()->role) }}
                </span>
            </div>
        </div>
    </div>

    {{-- Main Navigation (Scrollable) --}}
    <div class="flex-grow-1" style="overflow-y: auto;">
        <ul class="nav flex-column">

            {{-- ═══ MAIN ═══ --}}
            <li class="sidebar-section-label">MAIN</li>

            {{-- Dashboard --}}
            <li class="nav-item">
                <a class="nav-link {{ request()->routeIs('dashboard') ? 'active' : '' }}" href="{{ route('dashboard') }}">
                    <i class="fas fa-home"></i> Dashboard
                </a>
            </li>

            {{-- ═══ INTERNSHIP PROGRAM ═══ --}}
            @if(Auth::user()->isIntern())
            <li class="sidebar-section-label">INTERNSHIP</li>
            <li class="nav-item">
                <a class="nav-link {{ request()->routeIs('my-internship.*') ? 'active' : '' }}" href="{{ route('my-internship.index') }}">
                    <i class="fas fa-user-graduate"></i> My Internship
                </a>
            </li>
            @elseif(Auth::user()->hasRole('admin', 'peso_manager'))
            <li class="sidebar-section-label">INTERNSHIP PROGRAM</li>
            <li class="nav-item">
                <a class="nav-link {{ request()->routeIs('interns.*') ? 'active' : '' }}" href="{{ route('interns.index') }}">
                    <i class="fas fa-user-graduate"></i> Interns
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link {{ request()->routeIs('intern-documents.*') ? 'active' : '' }}" href="{{ route('intern-documents.index') }}">
                    <i class="fas fa-file-signature"></i> Intern Documents
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link {{ request()->routeIs('reports.intern') ? 'active' : '' }}" href="{{ route('reports.intern') }}">
                    <i class="fas fa-chart-bar"></i> Intern Reports
                </a>
            </li>
            @endif

            @if(Auth::user()->isAdmin())
            {{-- ═══ ADMINISTRATION ═══ --}}
            <li class="sidebar-section-label">ADMINISTRATION</li>

            {{-- User Management --}}
            <li class="nav-item">
                <a class="nav-link {{ request()->is('settings/users*') ? 'active' : '' }}" href="{{ route('settings.users.index') }}">
                    <i class="fas fa-user-cog"></i> User Management
                </a>
            </li>

            {{-- Settings Dropdown --}}
            <li class="nav-item" x-data="{ open: {{ request()->is('settings*') ? 'true' : 'false' }} }">
                <a class="nav-link {{ request()->is('settings*') ? 'active' : '' }}"
                   href="javascript:void(0)"
                   @click="open = !open"
                   style="display: flex; justify-content: space-between; align-items: center;">
                    <span>
                        <i class="fas fa-cogs"></i> Settings
                    </span>
                    <i class="fas" :class="open ? 'fa-chevron-down' : 'fa-chevron-right'" style="font-size: 0.7rem;"></i>
                </a>
                <ul class="nav flex-column ms-3" x-show="open" x-transition x-cloak>
                    <li class="nav-item">
                        <a class="nav-link {{ request()->is('settings/document-types*') ? 'active' : '' }}" href="{{ route('settings.document-types.index') }}">
                            <i class="fas fa-file-alt"></i> Document Types
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link {{ request()->is('settings/companies*') ? 'active' : '' }}" href="{{ route('settings.companies.index') }}">
                            <i class="fas fa-handshake"></i> Partner Agencies
                        </a>
                    </li>
                </ul>
            </li>

            {{-- Reports Dropdown --}}
            <li class="nav-item" x-data="{ open: {{ request()->is('reports/audit-log') ? 'true' : 'false' }} }">
                <a class="nav-link {{ request()->is('reports/audit-log') ? 'active' : '' }}"
                   href="javascript:void(0)"
                   @click="open = !open"
                   style="display: flex; justify-content: space-between; align-items: center;">
                    <span>
                        <i class="fas fa-chart-bar"></i> Reports
                    </span>
                    <i class="fas" :class="open ? 'fa-chevron-down' : 'fa-chevron-right'" style="font-size: 0.7rem;"></i>
                </a>
                <ul class="nav flex-column ms-3" x-show="open" x-transition x-cloak>
                    <li class="nav-item">
                        <a class="nav-link" href="{{ url('/reports/audit-log') }}">
                            <i class="fas fa-history"></i> Activity Logs
                        </a>
                    </li>
                </ul>
            </li>
            @endif

        </ul>
    </div>
</div>
