<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>FileStore Login</title>
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
                <span>FileStore</span>
            </div>
            <p>Management-Panel</p>
        </div>

        <div class="auth-title">
            <h1>Вход в панель</h1>
            <p>Используйте учетные данные администратора.</p>
        </div>

        <div class="auth-error" data-auth-error hidden>
            <i class="fas fa-circle-exclamation"></i>
            <span>Неверный логин или пароль</span>
        </div>

        <form class="admin-form" data-auth-form>
            <div class="form-group">
                <label for="username">Логин</label>
                <input id="username" name="username" class="glass-input" autocomplete="username" required autofocus>
            </div>
            <div class="form-group">
                <label for="password">Пароль</label>
                <input id="password" name="password" type="password" class="glass-input" autocomplete="current-password" required>
            </div>
            <button class="btn btn-primary auth-submit" type="submit">
                <i class="fas fa-right-to-bracket"></i>
                Войти
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
                'Authorization': 'Bearer IRci4Fkjcc348DHDudeEY3dCEu38xEUWm95cEICeuEEFUncur4842',
            },
            body: JSON.stringify({
                username: form.username.value,
                password: form.password.value,
            }),
        })
            .then(async response => {
                if (!response.ok) {
                    throw new Error('auth');
                }
                return response.json();
            })
            .then(data => {
                localStorage.setItem('s4w_jwt', data.token || '');
                document.body.classList.add('page-leaving');
                window.setTimeout(() => {
                    window.location.href = '/web/main';
                }, 220);
            })
            .catch(() => {
                document.querySelector('[data-auth-error]').hidden = false;
                form.dataset.submitting = 'false';
            });
    });
</script>
</body>
</html>
