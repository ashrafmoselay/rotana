<!doctype html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>روتانا | تسجيل الدخول</title>
    <link rel="stylesheet" href="/assets/vendor/bootstrap.rtl.min.css">
    <link rel="stylesheet" href="/assets/vendor/css/all.min.css">
    <link rel="stylesheet" href="/assets/app.css">
    <link rel="stylesheet" href="/assets/login.css">
</head>
<body class="auth-page">
    <main class="auth-layout">
        <section class="auth-brand-panel" aria-label="هوية روتانا">
            <div class="auth-brand-content">
                <div class="auth-logo-wrap"><img class="auth-logo" src="/assets/brand/rotana-logo.jpg" alt="روتانا للسيارات"></div>
                <div class="auth-hero-copy">
                    <span class="auth-eyebrow"><i class="fa-solid fa-sparkles" aria-hidden="true"></i> منصة تشغيل موحّدة</span>
                    <h1>تشغيل أكثر ذكاءً، من مكان واحد.</h1>
                    <p>أدر الأسطول والمشتريات والصيانة والمخزون بثقة ووضوح.</p>
                </div>
            </div>
            <div class="auth-brand-points">
                <div><i class="fa-solid fa-diagram-project" aria-hidden="true"></i><span><strong>مشتريات مترابطة</strong><small>من المسودة حتى الإغلاق</small></span></div>
                <div><i class="fa-solid fa-chart-line" aria-hidden="true"></i><span><strong>تشغيل أوضح</strong><small>الصيانة والمخزون والمدفوعات</small></span></div>
            </div>
        </section>
        <section class="auth-form-panel">
            <form method="post" action="/login" class="auth-card" id="login-form" novalidate>
                @csrf
                <div class="auth-card-head">
                    <div class="auth-icon"><img src="/assets/brand/rotana-logo.jpg" alt="" aria-hidden="true"></div>
                    <div>
                        <h2>أهلًا بعودتك</h2>
                        <p>أدخل بياناتك للوصول إلى حسابك.</p>
                    </div>
                </div>

                @if($errors->any())
                    <div class="alert alert-danger" role="alert" aria-live="assertive">{{ $errors->first() }}</div>
                @endif

                <div class="auth-field">
                    <label class="form-label" for="email">البريد الإلكتروني</label>
                    <div class="input-icon-wrap">
                        <span class="input-icon" aria-hidden="true"><i class="fa-regular fa-envelope"></i></span>
                        <input id="email" class="form-control auth-input @error('email') is-invalid @enderror" name="email" type="email" value="{{ old('email') }}" required autocomplete="username" dir="ltr" inputmode="email" aria-describedby="@error('email') email-error @enderror">
                    </div>
                    @error('email')<div class="invalid-feedback d-block" id="email-error">{{ $message }}</div>@enderror
                </div>

                <div class="auth-field auth-password-field">
                    <label class="form-label" for="password">كلمة المرور</label>
                    <div class="input-icon-wrap password-wrap">
                        <span class="input-icon" aria-hidden="true"><i class="fa-solid fa-lock"></i></span>
                        <input id="password" class="form-control auth-input @error('password') is-invalid @enderror" name="password" type="password" required autocomplete="current-password" aria-describedby="@error('password') password-error @enderror">
                        <button class="password-toggle" id="password-toggle" type="button" aria-controls="password" aria-label="إظهار كلمة المرور" data-show-label="إظهار كلمة المرور" data-hide-label="إخفاء كلمة المرور">
                            <i class="fa-regular fa-eye" aria-hidden="true"></i>
                            <span>إظهار</span>
                        </button>
                    </div>
                    @error('password')<div class="invalid-feedback d-block" id="password-error">{{ $message }}</div>@enderror
                </div>

                <button class="btn btn-primary auth-submit" type="submit">
                    <span class="submit-label">تسجيل الدخول <i class="fa-solid fa-arrow-left" aria-hidden="true"></i></span>
                    <span class="submit-progress" hidden>جارٍ التحقق...</span>
                </button>
                <p class="auth-security-note"><i class="fa-solid fa-shield-halved" aria-hidden="true"></i> اتصالك محمي وآمن</p>
            </form>
        </section>
    </main>
    <script>
        const loginForm = document.getElementById('login-form');
        const passwordInput = document.getElementById('password');
        const passwordToggle = document.getElementById('password-toggle');
        passwordToggle?.addEventListener('click', () => {
            const isPassword = passwordInput.type === 'password';
            passwordInput.type = isPassword ? 'text' : 'password';
            passwordToggle.setAttribute('aria-label', isPassword ? passwordToggle.dataset.hideLabel : passwordToggle.dataset.showLabel);
            passwordToggle.querySelector('span').textContent = isPassword ? 'إخفاء' : 'إظهار';
            passwordToggle.querySelector('i').className = isPassword ? 'fa-regular fa-eye-slash' : 'fa-regular fa-eye';
        });
        loginForm?.addEventListener('submit', () => {
            const button = loginForm.querySelector('.auth-submit');
            button.disabled = true;
            button.querySelector('.submit-label').hidden = true;
            button.querySelector('.submit-progress').hidden = false;
        });
    </script>
</body>
</html>
