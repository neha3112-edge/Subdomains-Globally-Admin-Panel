<?php
require_once __DIR__ . '/config/config.php';

if (is_logged_in()) {
    redirect(BASE_URL . '/dashboard.php');
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $identifier = trim($_POST['identifier'] ?? '');
    $password = $_POST['password'] ?? '';
    $latitude = trim($_POST['latitude'] ?? '');
    $longitude = trim($_POST['longitude'] ?? '');
    $location_address = trim($_POST['location_address'] ?? '');
    $location_accuracy = trim($_POST['location_accuracy'] ?? '');

    if (empty($identifier) || empty($password)) {
        $error = 'Please enter both email/username and password.';
    } elseif (empty($latitude) || empty($longitude)) {
        $error = '📍 Location access is required to sign in. Please enable location permissions in your browser and try again.';
        if (function_exists('log_login_attempt')) {
            log_login_attempt($identifier, 'FAILED', 'Blocked: Location Permission Denied/Missing');
        }
    } else {
        $location_data = [
            'latitude' => $latitude,
            'longitude' => $longitude,
            'location_address' => $location_address,
            'location_accuracy' => $location_accuracy
        ];
        $res = attempt_login($identifier, $password, $location_data);
        if ($res['success']) {
            redirect(BASE_URL . '/dashboard.php');
        } else {
            $error = $res['message'];
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en" data-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sign In - <?php echo htmlspecialchars(APP_NAME); ?></title>
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/admin.css?v=<?php echo time(); ?>">
    <style>
        .login-page-wrap {
            min-height: 100vh;
            width: 100vw;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 24px;
            background: radial-gradient(circle at top center, rgba(79, 70, 229, 0.18) 0%, rgba(9, 14, 23, 1) 75%);
            position: relative;
            overflow: hidden;
        }

        [data-theme="light"] .login-page-wrap {
            background: radial-gradient(circle at top center, rgba(79, 70, 229, 0.08) 0%, #f4f6fb 75%);
        }

        .login-top-actions {
            position: absolute;
            top: 24px;
            right: 24px;
            display: flex;
            align-items: center;
            gap: 12px;
            z-index: 10;
        }

        .login-card {
            width: 100%;
            max-width: 440px;
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: var(--radius-xl);
            padding: 38px 32px;
            box-shadow: 0 20px 50px rgba(0, 0, 0, 0.5);
            position: relative;
            z-index: 5;
            animation: fadeIn 0.4s ease;
        }

        [data-theme="light"] .login-card {
            box-shadow: 0 15px 40px rgba(0, 0, 0, 0.08);
        }

        .login-header {
            text-align: center;
            margin-bottom: 24px;
        }

        .login-brand-icon {
            width: 56px;
            height: 56px;
            margin: 0 auto 16px;
            background: var(--primary-gradient);
            border-radius: var(--radius-lg);
            display: flex;
            align-items: center;
            justify-content: center;
            color: #fff;
            box-shadow: 0 10px 25px rgba(79, 70, 229, 0.45);
        }

        .login-title {
            font-size: 24px;
            font-weight: 800;
            color: var(--text-main);
            letter-spacing: -0.4px;
        }

        .login-sub {
            font-size: 13px;
            color: var(--text-dim);
            margin-top: 6px;
            font-weight: 400;
        }

        .input-icon-wrap {
            position: relative;
        }

        .input-icon {
            position: absolute;
            left: 14px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--text-dim);
            pointer-events: none;
            display: flex;
            align-items: center;
        }

        .form-control-icon {
            padding-left: 42px;
        }

        .password-toggle-icon {
            position: absolute;
            right: 14px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            color: var(--text-dim);
            cursor: pointer;
            padding: 4px;
            display: flex;
            align-items: center;
            transition: color 0.2s;
        }

        .password-toggle-icon:hover {
            color: var(--text-main);
        }

        /* Location Card */
        .location-gate-card {
            border-radius: var(--radius-md);
            padding: 12px 14px;
            margin-bottom: 18px;
            font-size: 12px;
            display: flex;
            align-items: flex-start;
            gap: 10px;
            transition: all 0.3s ease;
        }

        .location-gate-card.loc-pending {
            background: rgba(245, 158, 11, 0.08);
            border: 1px solid rgba(245, 158, 11, 0.25);
            color: #fbbf24;
        }

        .location-gate-card.loc-success {
            background: rgba(16, 185, 129, 0.08);
            border: 1px solid rgba(16, 185, 129, 0.28);
            color: #34d399;
        }

        .location-gate-card.loc-error {
            background: rgba(239, 68, 68, 0.08);
            border: 1px solid rgba(239, 68, 68, 0.3);
            color: #f87171;
        }

        .loc-icon-pulse {
            display: inline-block;
            animation: pulse 1.8s infinite;
        }

        @keyframes pulse {
            0%, 100% { transform: scale(1); opacity: 1; }
            50% { transform: scale(1.15); opacity: 0.7; }
        }

        .btn-retry-loc {
            background: rgba(239, 68, 68, 0.18);
            color: #f87171;
            border: 1px solid rgba(239, 68, 68, 0.35);
            padding: 4px 10px;
            border-radius: 4px;
            font-size: 11px;
            font-weight: 700;
            cursor: pointer;
            margin-top: 6px;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }
        .btn-retry-loc:hover {
            background: rgba(239, 68, 68, 0.28);
        }
    </style>
</head>
<body>

<div class="login-page-wrap">
    <div class="login-top-actions">
        <button type="button" class="theme-toggle-btn" id="theme-toggle-btn" title="Toggle Light / Dark Mode">
            <span id="theme-icon">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"></path>
                </svg>
            </span>
        </button>
    </div>

    <?php 
    $branding = function_exists('get_site_branding') ? get_site_branding() : [];
    $login_logo = $branding['admin_logo_url'] ?? $branding['site_logo_url'] ?? '';
    ?>
    <div class="login-card">
        <div class="login-header">
            <?php if (!empty($login_logo)): ?>
                <div style="text-align:center; margin-bottom:18px;">
                    <img src="<?php echo htmlspecialchars(function_exists('get_asset_url') ? get_asset_url($login_logo) : $login_logo); ?>" alt="<?php echo htmlspecialchars(APP_NAME); ?>" style="max-height:54px; max-width:220px; object-fit:contain;">
                </div>
            <?php else: ?>
                <div class="login-brand-icon">
                    <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                        <path d="M22 10v6M2 10l10-5 10 5-10 5z"></path>
                        <path d="M6 12v5c3 3 9 3 12 0v-5"></path>
                    </svg>
                </div>
            <?php endif; ?>
            <h1 class="login-title"><?php echo htmlspecialchars(APP_NAME); ?></h1>
            <p class="login-sub">Sign in to manage universal subdomains & records</p>
        </div>

        <?php if (!empty($error)): ?>
            <div class="admin-alert alert-error" style="margin-bottom: 16px;">
                <span><?php echo htmlspecialchars($error); ?></span>
            </div>
        <?php endif; ?>

        <!-- Location Access Status Widget -->
        <div id="locationGateCard" class="location-gate-card loc-pending">
            <div id="locIcon" class="loc-icon-pulse" style="font-size: 16px; margin-top: 1px;">📍</div>
            <div style="flex: 1;">
                <div id="locTitle" style="font-weight: 700; margin-bottom: 2px;">Requesting Location Access...</div>
                <div id="locDesc" style="color: inherit; opacity: 0.88; line-height: 1.4;">
                    Please click <strong>"Allow"</strong> on your browser prompt to verify your login location.
                </div>
                <button type="button" id="btnRetryLoc" class="btn-retry-loc" style="display: none;" onclick="requestUserLocation(true)">
                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21.5 2v6h-6M21.34 15.57a10 10 0 1 1-.57-8.38l5.67-5.67"/></svg>
                    Allow / Retry Location
                </button>
            </div>
        </div>

        <form id="loginForm" method="POST" action="">
            <?php echo csrf_field(); ?>

            <!-- Hidden Location Fields -->
            <input type="hidden" name="latitude" id="loc_latitude" value="">
            <input type="hidden" name="longitude" id="loc_longitude" value="">
            <input type="hidden" name="location_accuracy" id="loc_accuracy" value="">
            <input type="hidden" name="location_address" id="loc_address" value="">

            <div class="form-group">
                <label class="form-label">Email or Username</label>
                <div class="input-icon-wrap">
                    <span class="input-icon">
                        <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path>
                            <circle cx="12" cy="7" r="4"></circle>
                        </svg>
                    </span>
                    <input type="text" name="identifier" class="form-control form-control-icon" placeholder="admin or name@domain.com" required autofocus>
                </div>
            </div>

            <div class="form-group">
                <label class="form-label">Password</label>
                <div class="input-icon-wrap">
                    <span class="input-icon">
                        <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect>
                            <path d="M7 11V7a5 5 0 0 1 10 0v4"></path>
                        </svg>
                    </span>
                    <input type="password" name="password" id="login-password" class="form-control form-control-icon" placeholder="••••••••" style="padding-right: 44px;" required>
                    <button type="button" class="password-toggle-icon password-toggle" data-target="login-password" title="Show/Hide Password">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path>
                            <circle cx="12" cy="12" r="3"></circle>
                        </svg>
                    </button>
                </div>
            </div>

            <button type="submit" id="btnSubmitLogin" class="btn-primary" style="margin-top: 14px; padding: 13px 20px;">
                Sign In to Dashboard
            </button>
        </form>
    </div>
</div>

<script src="<?php echo BASE_URL; ?>/assets/js/admin.js"></script>

<script>
    let locationAcquired = false;
    let isRequesting = false;

    function updateLocationUI(status, title, desc, showRetry = false) {
        const card = document.getElementById('locationGateCard');
        const icon = document.getElementById('locIcon');
        const titleEl = document.getElementById('locTitle');
        const descEl = document.getElementById('locDesc');
        const retryBtn = document.getElementById('btnRetryLoc');

        if (!card) return;

        card.className = 'location-gate-card loc-' + status;
        titleEl.innerHTML = title;
        descEl.innerHTML = desc;
        retryBtn.style.display = showRetry ? 'inline-flex' : 'none';

        if (status === 'success') {
            icon.innerText = '🛡️';
            icon.className = '';
        } else if (status === 'error') {
            icon.innerText = '🚫';
            icon.className = '';
        } else {
            icon.innerText = '📍';
            icon.className = 'loc-icon-pulse';
        }
    }

    function requestUserLocation(userTriggered = false) {
        if (!navigator.geolocation) {
            updateLocationUI('error', 'Geolocation Unsupported', 'Your browser does not support Geolocation. Please use a modern browser to sign in.', false);
            return;
        }

        isRequesting = true;
        updateLocationUI('pending', 'Requesting Exact Location...', 'Please click <strong>"Allow"</strong> when prompted to access your location.', false);

        const options = {
            enableHighAccuracy: true,
            timeout: 10000,
            maximumAge: 0
        };

        navigator.geolocation.getCurrentPosition(
            async function(position) {
                const lat = position.coords.latitude;
                const lng = position.coords.longitude;
                const acc = Math.round(position.coords.accuracy);

                document.getElementById('loc_latitude').value = lat;
                document.getElementById('loc_longitude').value = lng;
                document.getElementById('loc_accuracy').value = acc + 'm';

                locationAcquired = true;
                isRequesting = false;

                updateLocationUI('success', 'Location Access Granted', `Coordinates: ${lat.toFixed(4)}, ${lng.toFixed(4)} (±${acc}m accuracy)`, false);

                // Optional: fast reverse geocode for human readable city/state/country
                try {
                    const controller = new AbortController();
                    const timeoutId = setTimeout(() => controller.abort(), 2500);
                    const res = await fetch(`https://nominatim.openstreetmap.org/reverse?format=json&lat=${lat}&lon=${lng}&zoom=12`, {
                        signal: controller.signal
                    });
                    clearTimeout(timeoutId);
                    if (res.ok) {
                        const data = await res.json();
                        if (data && data.display_name) {
                            const addrParts = [];
                            if (data.address) {
                                if (data.address.city || data.address.town || data.address.village || data.address.suburb) {
                                    addrParts.push(data.address.city || data.address.town || data.address.village || data.address.suburb);
                                }
                                if (data.address.state) addrParts.push(data.address.state);
                                if (data.address.country) addrParts.push(data.address.country);
                            }
                            const cleanAddr = addrParts.length > 0 ? addrParts.join(', ') : data.display_name.split(',').slice(0, 3).join(', ');
                            document.getElementById('loc_address').value = cleanAddr;
                            updateLocationUI('success', 'Location Verified 📍', `<strong>${cleanAddr}</strong> (±${acc}m)`, false);
                        }
                    }
                } catch (e) {
                    // Reverse geocoding optional, coordinates are already set
                }

                // If user pressed submit while waiting, submit form now
                if (userTriggered && window.pendingFormSubmit) {
                    window.pendingFormSubmit = false;
                    document.getElementById('loginForm').submit();
                }
            },
            function(error) {
                locationAcquired = false;
                isRequesting = false;
                window.pendingFormSubmit = false;

                let errDesc = 'Location access is required to sign in. Please allow location access in your browser.';
                if (error.code === error.PERMISSION_DENIED) {
                    errDesc = 'Location access was <strong>blocked/denied</strong>. Please click the permissions icon in your browser address bar (top left), set Location to <strong>"Allow"</strong>, and click Retry.';
                } else if (error.code === error.POSITION_UNAVAILABLE) {
                    errDesc = 'Location information is currently unavailable from your device. Please verify your device GPS/Wi-Fi is on.';
                } else if (error.code === error.TIMEOUT) {
                    errDesc = 'Location request timed out. Please click Retry to request location again.';
                }

                updateLocationUI('error', 'Location Access Required', errDesc, true);
            },
            options
        );
    }

    // Auto-prompt on load
    document.addEventListener('DOMContentLoaded', function() {
        requestUserLocation(false);
    });

    // Handle Form Submit
    document.getElementById('loginForm').addEventListener('submit', function(e) {
        const lat = document.getElementById('loc_latitude').value;
        const lng = document.getElementById('loc_longitude').value;

        if (!lat || !lng || !locationAcquired) {
            e.preventDefault();
            window.pendingFormSubmit = true;
            updateLocationUI('pending', 'Location Permission Required', 'Please click <strong>"Allow"</strong> in your browser prompt to proceed with login.', true);
            requestUserLocation(true);
        }
    });
</script>
</body>
</html>

