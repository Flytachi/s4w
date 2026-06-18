<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>S4W Login</title>
    <link rel="stylesheet" href="/static/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body class="auth-body">
<div class="background-blobs">
    <div class="blob blob-1"></div>
    <div class="blob blob-2"></div>
    <div class="blob blob-3"></div>
</div>

<main class="auth-shell">
    <section class="auth-panel glass">
        <div class="auth-brand">
            <div class="logo">
                <i class="fas fa-database"></i>
                <span>S4W</span>
            </div>
            <p>Management Panel</p>
        </div>

        <div class="auth-title">
            <h1>Sign in</h1>
            <p>Use administrator credentials.</p>
        </div>

        <div class="auth-error" data-auth-error hidden>
            <i class="fas fa-circle-exclamation"></i>
            <span>Invalid login or password</span>
        </div>

        <form class="admin-form" data-auth-form>
            <div class="form-group">
                <label for="username">Login</label>
                <input id="username" name="username" class="glass-input" autocomplete="username" required autofocus>
            </div>
            <div class="form-group">
                <label for="password">Password</label>
                <input id="password" name="password" type="password" class="glass-input" autocomplete="current-password" required>
            </div>
            <button class="btn btn-primary auth-submit" type="submit">
                <i class="fas fa-right-to-bracket"></i>
                Sign in
            </button>
        </form>
    </section>
</main>
<script>
    document.querySelector('[data-auth-form]')?.addEventListener('submit', event => {
        const form = event.currentTarget;
        if (form.dataset.submitting === 'true') {
            return;
        }

        event.preventDefault();
        form.dataset.submitting = 'true';
        fetch('/s4w/auth', {
            method: 'POST',
            headers: {
                'Accept': 'application/json',
                'Content-Type': 'application/json',
                'Authorization': 'Bearer <?= env('TOKEN', '') ?>',
            },
            body: JSON.stringify({
                username: form.username.value,
                password: form.password.value,
            }),
        })
            .then(async response => {
                if (response.status === 429) {
                    const retry = Number(response.headers.get('Retry-After'));
                    const mins = retry ? Math.ceil(retry / 60) : null;
                    throw new Error(mins
                        ? `Too many attempts. Try again in ~${mins} min.`
                        : 'Too many attempts. Try again later.');
                }
                if (!response.ok) {
                    throw new Error('Invalid login or password');
                }
                return response.json();
            })
            .then(data => {
                localStorage.setItem('s4w_jwt', data.token || '');
                document.body.classList.add('page-leaving');
                window.setTimeout(() => {
                    window.location.href = '/web';
                }, 220);
            })
            .catch(error => {
                const box = document.querySelector('[data-auth-error]');
                box.querySelector('span').textContent = error.message || 'Invalid login or password';
                box.hidden = false;
                form.dataset.submitting = 'false';
            });
    });
</script>
</body>
</html>
