<?php
/**
 * NGender 配置管理后台 (异步保存 + 深色主题 + 防XSS)
 */
define('USER_DB_FILE', __DIR__ . '/u.db');
define('CONFIG_DB_FILE', __DIR__ . '/o.db');

// ---------- 辅助函数 ----------
function initUserDB() {
    if (!file_exists(USER_DB_FILE)) {
        $defaultUser = [
            'username' => 'admin',
            'password' => password_hash('admin123', PASSWORD_DEFAULT)
        ];
        file_put_contents(USER_DB_FILE, json_encode($defaultUser, JSON_PRETTY_PRINT));
        @chmod(USER_DB_FILE, 0600);
    }
}

function getUserData() {
    initUserDB();
    return json_decode(file_get_contents(USER_DB_FILE), true);
}

function saveUserData($data) {
    file_put_contents(USER_DB_FILE, json_encode($data, JSON_PRETTY_PRINT));
    @chmod(USER_DB_FILE, 0600);
}

function getConfig() {
    if (!file_exists(CONFIG_DB_FILE)) {
        $secret = bin2hex(random_bytes(16));
        $defaultConfig = [
            'secret_key' => $secret,
            'disabled_params' => [],
            'blacklist' => [],
            'force_mapping' => [],
            'restrict_modes' => false,
            'custom_errors' => [
                'param_disabled' => ['code' => 404, 'msg' => '参数已被禁用'],
                'name_blacklisted' => ['code' => 403, 'msg' => '姓名在黑名单中'],
                'mode_restricted' => ['code' => 403, 'msg' => '当前模式已被限制'],
                'unknown_param' => ['code' => 404, 'msg' => '未知参数']
            ]
        ];
        file_put_contents(CONFIG_DB_FILE, json_encode($defaultConfig, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        @chmod(CONFIG_DB_FILE, 0600);
        return $defaultConfig;
    }
    $config = json_decode(file_get_contents(CONFIG_DB_FILE), true);
    // 兼容旧版无 secret_key
    if (!isset($config['secret_key'])) {
        $config['secret_key'] = bin2hex(random_bytes(16));
        file_put_contents(CONFIG_DB_FILE, json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }
    return $config;
}

function saveConfig($config) {
    file_put_contents(CONFIG_DB_FILE, json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    @chmod(CONFIG_DB_FILE, 0600);
}

$config = getConfig();
define('SECRET_KEY', $config['secret_key']);

// ---------- Cookie 认证 ----------
function sign($data) {
    return hash_hmac('sha256', $data, SECRET_KEY);
}

function isLoggedIn() {
    if (empty($_COOKIE['ng_auth'])) return false;
    $parts = explode(':', $_COOKIE['ng_auth']);
    if (count($parts) !== 2) return false;
    list($user, $sig) = $parts;
    return $sig === sign($user) && $user === getUserData()['username'];
}

function setLoginCookie($username) {
    $sig = sign($username);
    setcookie('ng_auth', $username . ':' . $sig, time() + 86400, '/', '', false, true);
}

function clearLoginCookie() {
    setcookie('ng_auth', '', time() - 3600, '/', '', false, true);
}

// ---------- 路由处理 ----------
$action = $_GET['action'] ?? '';

if ($action === 'login' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';
    $user = getUserData();
    if ($username === $user['username'] && password_verify($password, $user['password'])) {
        setLoginCookie($username);
        header('Location: dash.php');
        exit;
    } else {
        $error = '用户名或密码错误';
    }
}

if ($action === 'logout') {
    clearLoginCookie();
    header('Location: dash.php');
    exit;
}

if ($action === 'save_config' && isLoggedIn() && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) {
        echo json_encode(['success' => false, 'message' => '无效的请求数据']);
        exit;
    }

    // 处理 force_mapping：先 html_entity_decode，再 json_decode
    $forceMapping = [];
    $mappingStr = trim($input['force_mapping'] ?? '');
    if ($mappingStr !== '') {
        $decodedStr = html_entity_decode($mappingStr, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $decoded = json_decode($decodedStr, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            echo json_encode(['success' => false, 'message' => '强绑定映射表 JSON 格式错误: ' . json_last_error_msg()]);
            exit;
        }
        $forceMapping = $decoded;
    }

    $newConfig = [
        'secret_key' => $config['secret_key'],
        'disabled_params' => array_filter(array_map('trim', explode("\n", $input['disabled_params'] ?? ''))),
        'blacklist' => array_filter(array_map('trim', explode("\n", $input['blacklist'] ?? ''))),
        'force_mapping' => $forceMapping,
        'restrict_modes' => !empty($input['restrict_modes']),
        'custom_errors' => [
            'param_disabled' => [
                'code' => (int)($input['err_param_disabled_code'] ?? 404),
                'msg' => trim($input['err_param_disabled_msg'] ?? '参数已被禁用')
            ],
            'name_blacklisted' => [
                'code' => (int)($input['err_name_blacklisted_code'] ?? 403),
                'msg' => trim($input['err_name_blacklisted_msg'] ?? '姓名在黑名单中')
            ],
            'mode_restricted' => [
                'code' => (int)($input['err_mode_restricted_code'] ?? 403),
                'msg' => trim($input['err_mode_restricted_msg'] ?? '当前模式已被限制')
            ],
            'unknown_param' => [
                'code' => (int)($input['err_unknown_param_code'] ?? 404),
                'msg' => trim($input['err_unknown_param_msg'] ?? '未知参数')
            ]
        ]
    ];
    saveConfig($newConfig);
    echo json_encode(['success' => true, 'message' => '配置已保存']);
    exit;
}

if ($action === 'change_password' && isLoggedIn() && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $input = json_decode(file_get_contents('php://input'), true);
    $old = $input['old_password'] ?? '';
    $new1 = $input['new_password1'] ?? '';
    $new2 = $input['new_password2'] ?? '';
    $user = getUserData();
    if (!password_verify($old, $user['password'])) {
        echo json_encode(['success' => false, 'message' => '原密码错误']);
        exit;
    } elseif ($new1 !== $new2) {
        echo json_encode(['success' => false, 'message' => '两次新密码输入不一致']);
        exit;
    } elseif (strlen($new1) < 6) {
        echo json_encode(['success' => false, 'message' => '新密码长度至少6位']);
        exit;
    }
    $user['password'] = password_hash($new1, PASSWORD_DEFAULT);
    saveUserData($user);
    clearLoginCookie();
    echo json_encode(['success' => true, 'message' => '密码修改成功，请重新登录']);
    exit;
}

// ---------- 页面输出 ----------
if (!isLoggedIn()):
?>
<!DOCTYPE html>
<html lang="zh">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>NPAL 控制台 · 登录</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Inter', system-ui, -apple-system, sans-serif; background: #0f172a; color: #f1f5f9; display: flex; align-items: center; justify-content: center; min-height: 100vh; margin: 0; }
        .login-card { background: #1e293b; border-radius: 20px; padding: 40px; width: 100%; max-width: 400px; box-shadow: 0 25px 50px -12px rgba(0,0,0,0.5); border: 1px solid #334155; }
        h2 { font-size: 24px; font-weight: 600; margin-bottom: 24px; text-align: center; color: #e2e8f0; }
        label { display: block; font-size: 14px; margin-bottom: 6px; color: #94a3b8; }
        input { width: 100%; padding: 12px 16px; background: #0f172a; border: 1px solid #334155; border-radius: 12px; color: #f1f5f9; font-size: 16px; margin-bottom: 20px; transition: border 0.2s; }
        input:focus { outline: none; border-color: #8b5cf6; box-shadow: 0 0 0 3px rgba(139, 92, 246, 0.2); }
        button { width: 100%; padding: 12px; background: #8b5cf6; color: white; font-weight: 600; border: none; border-radius: 12px; font-size: 16px; cursor: pointer; transition: background 0.2s, transform 0.1s; }
        button:hover { background: #7c3aed; }
        button:active { transform: scale(0.98); }
        .error { background: #7f1d1d; color: #fca5a5; padding: 10px; border-radius: 10px; margin-bottom: 20px; font-size: 14px; }
        .hint { text-align: center; margin-top: 20px; font-size: 14px; color: #64748b; }
        .hint code { background: #1e293b; padding: 2px 6px; border-radius: 6px; }
    </style>
</head>
<body>
    <div class="login-card">
        <h2>NGender-PHP-API-Light 管理</h2>
        <?php if (isset($error)) echo "<div class='error'>" . htmlspecialchars($error) . "</div>"; ?>
        <form method="post" action="?action=login">
            <label>用户名</label>
            <input type="text" name="username" required autofocus>
            <label>密码</label>
            <input type="password" name="password" required>
            <button type="submit">登录</button>
        </form>
        <div class="hint">默认账号：<code>admin</code> / <code>admin123</code></div>
    </div>
</body>
</html>
<?php
else:
    $config = getConfig();
?>
<!DOCTYPE html>
<html lang="zh">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>NPAL 控制台 · 配置</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Inter', system-ui, sans-serif; background: #0f172a; color: #e2e8f0; padding: 24px; }
        .container { max-width: 1000px; margin: 0 auto; }
        .header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 30px; }
        h1 { font-size: 28px; font-weight: 600; background: linear-gradient(135deg, #a78bfa, #f472b6); -webkit-background-clip: text; -webkit-text-fill-color: transparent; background-clip: text; }
        .logout-btn { background: #334155; color: #cbd5e1; border: none; padding: 8px 18px; border-radius: 30px; font-weight: 500; cursor: pointer; text-decoration: none; transition: 0.2s; }
        .logout-btn:hover { background: #475569; color: white; }
        .card { background: #1e293b; border: 1px solid #334155; border-radius: 24px; padding: 28px; margin-bottom: 24px; box-shadow: 0 10px 30px -10px rgba(0,0,0,0.4); }
        .card-title { font-size: 20px; font-weight: 600; margin-bottom: 20px; display: flex; align-items: center; gap: 8px; color: #f1f5f9; border-bottom: 1px solid #334155; padding-bottom: 12px; }
        label { display: block; font-size: 14px; font-weight: 500; margin-bottom: 6px; color: #94a3b8; }
        textarea, input[type="text"], input[type="number"], input[type="password"] { width: 100%; padding: 12px 16px; background: #0f172a; border: 1px solid #334155; border-radius: 14px; color: #f1f5f9; font-size: 14px; margin-bottom: 16px; resize: vertical; font-family: 'SF Mono', 'Menlo', monospace; }
        textarea:focus, input:focus { outline: none; border-color: #8b5cf6; box-shadow: 0 0 0 3px rgba(139, 92, 246, 0.15); }
        .checkbox-row { display: flex; align-items: center; gap: 12px; margin: 16px 0; }
        .checkbox-row input[type="checkbox"] { width: 20px; height: 20px; accent-color: #8b5cf6; margin: 0; }
        .grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; }
        .error-item { background: #1e293b; border-radius: 16px; padding: 16px; border: 1px solid #334155; }
        .error-item label { margin-bottom: 4px; }
        .button-group { display: flex; gap: 16px; margin-top: 30px; }
        .btn { padding: 12px 28px; border-radius: 40px; font-weight: 600; border: none; cursor: pointer; font-size: 16px; transition: 0.2s; }
        .btn-primary { background: #8b5cf6; color: white; box-shadow: 0 8px 20px -6px #8b5cf6; }
        .btn-primary:hover { background: #7c3aed; transform: translateY(-2px); }
        .btn-secondary { background: #334155; color: #e2e8f0; }
        .btn-secondary:hover { background: #475569; }
        .toast { position: fixed; bottom: 30px; right: 30px; background: #10b981; color: white; padding: 14px 24px; border-radius: 40px; font-weight: 500; box-shadow: 0 15px 30px -10px #10b981; opacity: 0; transition: opacity 0.3s; pointer-events: none; z-index: 999; }
        .toast.error { background: #ef4444; box-shadow: 0 15px 30px -10px #ef4444; }
        .toast.show { opacity: 1; }
        .info-note { margin-top: 20px; font-size: 13px; color: #64748b; }
        .info-note code { background: #1e293b; padding: 2px 6px; border-radius: 8px; }
        .force-mapping-hint { font-size: 13px; color: #94a3b8; margin-top: -10px; margin-bottom: 16px; }
        .spinner { display: inline-block; width: 16px; height: 16px; border: 2px solid rgba(255,255,255,0.3); border-top-color: white; border-radius: 50%; animation: spin 0.6s linear infinite; margin-right: 6px; }
        @keyframes spin { to { transform: rotate(360deg); } }
        .btn:disabled { opacity: 0.6; cursor: not-allowed; }
        .modal-input { width: 100%; padding: 12px 16px; background: #0f172a; border: 1px solid #334155; border-radius: 14px; color: #f1f5f9; font-size: 14px; margin-bottom: 16px; }
    </style>
</head>
<body>
<div class="container">
    <div class="header">
        <h1>NGender-PHP-API-Light 配置中心</h1>
        <a class="logout-btn" href="?action=logout">退出登录</a>
    </div>

    <form id="configForm">
        <div class="card">
            <div class="card-title"><span>[!] 基础设置</span></div>
            <label>禁用参数（每行一个参数名）</label>
            <textarea name="disabled_params" rows="3"><?= htmlspecialchars(implode("\n", $config['disabled_params']), ENT_QUOTES) ?></textarea>

            <label>黑名单姓名（每行一个）</label>
            <textarea name="blacklist" rows="3"><?= htmlspecialchars(implode("\n", $config['blacklist']), ENT_QUOTES) ?></textarea>

            <div class="checkbox-row">
                <input type="checkbox" name="restrict_modes" id="restrict_modes" <?= $config['restrict_modes'] ? 'checked' : '' ?>>
                <label for="restrict_modes" style="margin:0;">限制仅允许普通模式 (method=0)</label>
            </div>
        </div>

        <div class="card">
            <div class="card-title"><span>[*] 强绑定映射表 (JSON)</span></div>
            <textarea name="force_mapping" rows="8" style="font-family: monospace;"><?= htmlspecialchars(json_encode($config['force_mapping'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), ENT_QUOTES) ?></textarea>
            <div class="force-mapping-hint">格式：<code>{"张三":{"gender":"male","min":0.8,"max":0.95}, "李四":["female",0.9,1]}</code></div>
        </div>

        <div class="card">
            <div class="card-title"><span>[#] 自定义错误响应</span></div>
            <div class="grid-2">
                <?php
                $errors = [
                    'param_disabled' => '参数被禁用',
                    'name_blacklisted' => '姓名在黑名单',
                    'mode_restricted' => '模式被限制',
                    'unknown_param' => '未知参数'
                ];
                foreach ($errors as $key => $label):
                    $code = $config['custom_errors'][$key]['code'] ?? 404;
                    $msg = htmlspecialchars($config['custom_errors'][$key]['msg'] ?? '', ENT_QUOTES);
                ?>
                <div class="error-item">
                    <label><?= htmlspecialchars($label) ?></label>
                    <div style="display: flex; gap: 8px;">
                        <input type="number" name="err_<?= $key ?>_code" value="<?= $code ?>" placeholder="状态码" style="width: 100px;">
                        <input type="text" name="err_<?= $key ?>_msg" value="<?= $msg ?>" placeholder="错误消息">
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="button-group">
            <button type="submit" class="btn btn-primary" id="saveBtn">保存配置</button>
            <button type="button" class="btn btn-secondary" id="changePwdBtn">修改密码</button>
        </div>
    </form>

    <div id="pwdModal" style="display: none; position: fixed; top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.6);align-items:center;justify-content:center; z-index:1000;">
        <div style="background:#1e293b; border-radius:24px; padding:30px; width:400px;">
            <h3 style="margin-bottom:20px;">修改密码</h3>
            <input type="password" id="old_pwd" class="modal-input" placeholder="原密码">
            <input type="password" id="new_pwd1" class="modal-input" placeholder="新密码">
            <input type="password" id="new_pwd2" class="modal-input" placeholder="确认新密码">
            <div style="display:flex; gap:12px; margin-top:20px;">
                <button class="btn btn-primary" id="submitPwdBtn">确认修改</button>
                <button class="btn btn-secondary" id="closePwdModal">取消</button>
            </div>
            <div id="pwdMsg" style="margin-top:12px;"></div>
        </div>
    </div>

    <div class="info-note">
        配置文件：<code><?= htmlspecialchars(CONFIG_DB_FILE) ?></code> | 用户库：<code><?= htmlspecialchars(USER_DB_FILE) ?></code>
    </div>
</div>

<div id="toast" class="toast"></div>

<script>
    (function() {
        const form = document.getElementById('configForm');
        const saveBtn = document.getElementById('saveBtn');
        const toast = document.getElementById('toast');
        const pwdModal = document.getElementById('pwdModal');
        const changePwdBtn = document.getElementById('changePwdBtn');
        const closePwdModal = document.getElementById('closePwdModal');
        const submitPwdBtn = document.getElementById('submitPwdBtn');
        const pwdMsg = document.getElementById('pwdMsg');

        function showToast(msg, isError = false) {
            toast.textContent = msg;
            toast.classList.add('show');
            if (isError) toast.classList.add('error'); else toast.classList.remove('error');
            setTimeout(() => toast.classList.remove('show'), 3000);
        }

        form.addEventListener('submit', async (e) => {
            e.preventDefault();
            saveBtn.disabled = true;
            saveBtn.innerHTML = '<span class="spinner"></span>保存中...';
            const formData = new FormData(form);
            const payload = {
                disabled_params: formData.get('disabled_params') || '',
                blacklist: formData.get('blacklist') || '',
                force_mapping: formData.get('force_mapping') || '{}',
                restrict_modes: formData.get('restrict_modes') === 'on',
                err_param_disabled_code: formData.get('err_param_disabled_code'),
                err_param_disabled_msg: formData.get('err_param_disabled_msg'),
                err_name_blacklisted_code: formData.get('err_name_blacklisted_code'),
                err_name_blacklisted_msg: formData.get('err_name_blacklisted_msg'),
                err_mode_restricted_code: formData.get('err_mode_restricted_code'),
                err_mode_restricted_msg: formData.get('err_mode_restricted_msg'),
                err_unknown_param_code: formData.get('err_unknown_param_code'),
                err_unknown_param_msg: formData.get('err_unknown_param_msg')
            };
            try {
                const res = await fetch('?action=save_config', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify(payload)
                });
                const data = await res.json();
                if (data.success) {
                    showToast('[OK] 配置已保存');
                } else {
                    showToast('[ERROR] ' + data.message, true);
                }
            } catch (err) {
                showToast('[ERROR] 网络错误', true);
            } finally {
                saveBtn.disabled = false;
                saveBtn.innerHTML = '保存配置';
            }
        });

        changePwdBtn.addEventListener('click', () => {
            pwdModal.style.display = 'flex';
            document.getElementById('old_pwd').value = '';
            document.getElementById('new_pwd1').value = '';
            document.getElementById('new_pwd2').value = '';
            pwdMsg.innerHTML = '';
        });
        closePwdModal.addEventListener('click', () => pwdModal.style.display = 'none');
        submitPwdBtn.addEventListener('click', async () => {
            const old = document.getElementById('old_pwd').value;
            const new1 = document.getElementById('new_pwd1').value;
            const new2 = document.getElementById('new_pwd2').value;
            if (!old || !new1 || !new2) {
                pwdMsg.innerHTML = '<span style="color:#ef4444">所有字段都必须填写</span>';
                return;
            }
            submitPwdBtn.disabled = true;
            try {
                const res = await fetch('?action=change_password', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({old_password: old, new_password1: new1, new_password2: new2})
                });
                const data = await res.json();
                if (data.success) {
                    pwdMsg.innerHTML = '<span style="color:#10b981">密码修改成功，即将跳转登录页...</span>';
                    setTimeout(() => window.location.href = 'dash.php', 2000);
                } else {
                    pwdMsg.innerHTML = '<span style="color:#ef4444">' + data.message + '</span>';
                }
            } catch {
                pwdMsg.innerHTML = '<span style="color:#ef4444">请求失败</span>';
            } finally {
                submitPwdBtn.disabled = false;
            }
        });
        pwdModal.addEventListener('click', (e) => { if (e.target === pwdModal) pwdModal.style.display = 'none'; });
    })();
</script>
</body>
</html>
<?php endif; ?>