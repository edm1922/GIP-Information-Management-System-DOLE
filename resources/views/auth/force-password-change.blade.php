<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>{{ config('brand.name', 'GIMS') }} - Change Password</title>
    <link rel="icon" type="image/png" href="{{ asset(config('brand.logo')) }}">

    <!-- Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    
    <!-- Icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

    <!-- Styles -->
    <link rel="stylesheet" href="{{ asset('css/style.css') }}">
    <style>
        :root {
            --company-primary: {{ config('brand.primary_color') }};
            --company-primary-hover: {{ config('brand.primary_color') }}dd;
            --company-primary-light: {{ config('brand.primary_color') }}1a;
            --company-primary-border: {{ config('brand.primary_color') }}33;
            --brand-red: {{ config('brand.primary_color') }};
        }

        .input-group-custom {
            position: relative;
            width: 100%;
            margin-bottom: 14px;
        }

        .input-group-custom input {
            background-color: #f3f4f6;
            border: 1px solid #e5e7eb;
            padding: 12px 42px 12px 16px;
            width: 100%;
            border-radius: 10px;
            outline: none;
            font-size: 0.9rem;
            color: #1f2937;
            transition: all 0.2s ease;
            box-sizing: border-box;
        }

        .input-group-custom input:focus {
            border-color: var(--company-primary);
            background-color: #ffffff;
            box-shadow: 0 0 0 3px var(--company-primary-light);
        }

        .input-group-custom .toggle-btn {
            position: absolute;
            right: 14px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            color: #9ca3af;
            cursor: pointer;
            padding: 0;
            font-size: 0.9rem;
            transition: color 0.15s;
        }

        .input-group-custom .toggle-btn:hover {
            color: #4b5563;
        }

        .btn-submit {
            width: 100%;
            padding: 12px 20px;
            background-color: var(--company-primary);
            color: #ffffff;
            border: none;
            border-radius: 10px;
            font-size: 0.9rem;
            font-weight: 600;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            transition: all 0.2s ease;
            box-shadow: 0 4px 6px -1px var(--company-primary-light);
        }

        .btn-submit:hover {
            transform: translateY(-1px);
            box-shadow: 0 6px 12px -1px var(--company-primary-border);
            opacity: 0.95;
        }

        .btn-logout {
            background: none;
            border: none;
            color: #6b7280;
            font-size: 0.82rem;
            font-weight: 500;
            cursor: pointer;
            padding: 6px 12px;
            border-radius: 6px;
            transition: all 0.15s;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            text-decoration: none;
        }

        .btn-logout:hover {
            color: #111827;
            background-color: #f3f4f6;
        }
    </style>
</head>
<body class="login-page-wrapper">

    <div class="login-container">
        <!-- Left Side: Overlay (Security Notice) -->
        <div class="overlay-panel">
            <img src="{{ asset(config('brand.logo_full')) }}" alt="{{ config('brand.short_name') }} Logo" style="height: 120px; margin-bottom: 16px; max-width: 90%; object-fit: contain;">

            <h2 style="font-size: 1.4rem; font-weight: 700; margin-bottom: 10px; color: #111827;">Security Notice</h2>
            <p style="font-size: 0.88rem; line-height: 1.6; color: #4b5563; margin-bottom: 20px;">
                As this is your first time logging in, you are required to change your temporary default password before proceeding.
            </p>

            @if(auth()->check())
                <div style="background: rgba(30, 66, 155, 0.08); border: 1px solid var(--company-primary-border); padding: 8px 16px; border-radius: 20px; font-size: 0.8rem; color: var(--company-primary); font-weight: 600; display: inline-flex; align-items: center; gap: 6px;">
                    <i class="fas fa-user-circle"></i>
                    <span>{{ auth()->user()->username }}</span>
                    <span style="opacity: 0.6;">&bull;</span>
                    <span style="text-transform: capitalize;">{{ str_replace('_', ' ', auth()->user()->role) }}</span>
                </div>
            @endif
        </div>

        <!-- Right Side: Change Password Form -->
        <div class="form-panel">
            <form method="POST" action="{{ route('password.force-change.store') }}" style="width: 100%; display: flex; flex-direction: column; align-items: center;">
                @csrf

                <div style="width: 44px; height: 44px; border-radius: 50%; background: var(--company-primary-light); color: var(--company-primary); display: flex; align-items: center; justify-content: center; font-size: 1.2rem; margin-bottom: 12px;">
                    <i class="fas fa-shield-alt"></i>
                </div>

                <h1 style="font-size: 1.6rem; font-weight: 800; margin: 0 0 6px 0; color: #111827;">Set New Password</h1>
                <span style="font-size: 0.82rem; color: #6b7280; margin-bottom: 20px; text-align: center;">Enter your new personal password to secure your account.</span>

                <!-- Session Status / Errors -->
                @if (session('status'))
                    <div style="width: 100%; margin-bottom: 16px; padding: 10px 14px; border-radius: 8px; background: #dcfce7; color: #15803d; font-size: 0.82rem; font-weight: 500;">
                        <i class="fas fa-check-circle me-1"></i> {{ session('status') }}
                    </div>
                @endif

                @if ($errors->any())
                    <div style="width: 100%; margin-bottom: 16px; padding: 10px 14px; border-radius: 8px; background: #fee2e2; border-left: 3px solid #ef4444; color: #b91c1c; font-size: 0.82rem; font-weight: 500; text-align: left;">
                        @foreach ($errors->all() as $error)
                            <div style="margin-bottom: 2px;"><i class="fas fa-exclamation-circle me-1"></i> {{ $error }}</div>
                        @endforeach
                    </div>
                @endif

                <!-- New Password Field -->
                <div class="input-group-custom">
                    <input type="password"
                           id="password"
                           name="password"
                           placeholder="New Password (min. 8 characters)"
                           required
                           autofocus
                           autocomplete="new-password">
                    <button type="button" class="toggle-btn" onclick="togglePassword('password', 'toggle-icon-1')" aria-label="Toggle password visibility">
                        <i id="toggle-icon-1" class="fas fa-eye"></i>
                    </button>
                </div>

                <!-- Confirm Password Field -->
                <div class="input-group-custom" style="margin-bottom: 22px;">
                    <input type="password"
                           id="password_confirmation"
                           name="password_confirmation"
                           placeholder="Confirm New Password"
                           required
                           autocomplete="new-password">
                    <button type="button" class="toggle-btn" onclick="togglePassword('password_confirmation', 'toggle-icon-2')" aria-label="Toggle password confirmation visibility">
                        <i id="toggle-icon-2" class="fas fa-eye"></i>
                    </button>
                </div>

                <!-- Submit Button -->
                <button type="submit" class="btn-submit">
                    <i class="fas fa-key"></i> Save &amp; Proceed
                </button>
            </form>

            <!-- Logout Instead -->
            <form method="POST" action="{{ route('logout') }}" style="margin-top: 18px;">
                @csrf
                <button type="submit" class="btn-logout">
                    <i class="fas fa-sign-out-alt"></i> Logout instead
                </button>
            </form>
        </div>
    </div>

    <script>
        function togglePassword(inputId, iconId) {
            const input = document.getElementById(inputId);
            const icon = document.getElementById(iconId);
            if (!input || !icon) return;

            if (input.type === 'password') {
                input.type = 'text';
                icon.classList.remove('fa-eye');
                icon.classList.add('fa-eye-slash');
            } else {
                input.type = 'password';
                icon.classList.remove('fa-eye-slash');
                icon.classList.add('fa-eye');
            }
        }
    </script>
</body>
</html>
