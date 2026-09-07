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
</head>
<body class="auth-page">
    <main class="auth-layout">
        <section class="auth-brand-panel" aria-label="هوية روتانا">
            <div class="auth-brand-badge"><i class="fa-solid fa-car-side" aria-hidden="true"></i><span>روتانا</span></div>
            <div>
                <h1>مرحبًا بعودتك</h1>
                <p>سجّل دخولك لإدارة الأسطول والمشتريات ومتابعة العمليات.</p>
            </div>
            <div class="auth-brand-points">
                <div><strong>مشتريات مترابطة</strong><span>متابعة الطلب من المسودة حتى الإغلاق.</span></div>
                <div><strong>تشغيل أوضح</strong><span>ربط الصيانة والمخزون والمدفوعات في شاشة واحدة.</span></div>
            </div>
        </section>
        <section class="auth-form-panel">
            <form method="post" action="/login" class="auth-card" id="login-form" novalidate>
                @csrf
                <div class="auth-card-head">
                    <div class="auth-icon"><i class="fa-solid fa-car-side" aria-hidden="true"></i></div>
                    <div>
                        <h2>تسجيل الدخول</h2>
                        <p>أدخل بيانات الحساب للمتابعة.</p>
                    </div>
                </div>

                @if($errors->any())
                    <div class="alert alert-danger" role="alert" aria-live="assertive">{{ $errors->first() }}</div>
                @endif

                <div class="mb-3">
                    <label class="form-label" for="email">البريد الإلكتروني</label>
                    <div class="input-icon-wrap">
                        <span class="input-icon" aria-hidden="true"><i class="fa-regular fa-envelope"></i></span>
                        <input id="email" class="form-control auth-input @error('email') is-invalid @enderror" name="email" type="email" value="{{ old('email') }}" required autocomplete="username" dir="ltr" inputmode="email" aria-describedby="@error('email') email-error @enderror">
                    </div>
                    @error('email')<div class="invalid-feedback d-block" id="email-error">{{ $message }}</div>@enderror
                </div>

                <div class="mb-4">
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
                    <span class="submit-label">تسجيل الدخول</span>
                    <span class="submit-progress" hidden>جارٍ التحقق...</span>
                </button>
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
