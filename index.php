<?php
/**
 * NGender 纯API版（支持配置管理）
 */
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// 前置检查
if (version_compare(PHP_VERSION, '7.0.0', '<')) jsonExit(500, 'PHP版本要求7.0及以上');
if (!extension_loaded('mbstring')) jsonExit(500, '缺少必要扩展：mbstring');

// 核心配置
define('BASE_MALE', 0.581915415729593);
define('BASE_FEMALE', 0.418084584270407);
define('JSON_FILE_PATH', __DIR__ . '/charfreq.json');
define('TIPS_JSON_FILE_PATH', __DIR__ . '/tips.json');
define('CONFIG_DB_FILE', __DIR__ . '/o.db');

// 模式定义
define('METHOD_NORMAL', 0);
define('METHOD_REVERSE', 1);
define('METHOD_OPPOSITE', 2);
define('METHOD_RANDOM', 3);
define('METHOD_LABELS', [
    METHOD_NORMAL   => '正常',
    METHOD_REVERSE  => '反转性别',
    METHOD_OPPOSITE => '反向性别',
    METHOD_RANDOM   => '随机模式'
]);

/**
 * 加载配置
 */
function loadConfig() {
    if (!file_exists(CONFIG_DB_FILE)) {
        // 若不存在，返回默认配置（不自动创建，以免污染）
        return [
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
    }
    $content = file_get_contents(CONFIG_DB_FILE);
    $config = json_decode($content, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        jsonExit(500, '配置文件 o.db 解析失败');
    }
    return $config;
}

$config = loadConfig();

function xssFilter($str) {
    if (is_null($str) || !is_string($str)) return '';
    return htmlspecialchars(trim($str), ENT_QUOTES | ENT_HTML5, 'UTF-8', false);
}

function jsonExit($code = 200, $msg = '查询成功', $data = []) {
    http_response_code($code);
    echo json_encode(['code'=>$code,'msg'=>$msg,'data'=>$data], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

function checkName($name, $limitLength = true) {
    if ($limitLength) {
        return preg_match('/^[\x{4e00}-\x{9fa5}]{2,4}$/u', $name);
    } else {
        return preg_match('/^[\x{4e00}-\x{9fa5}]+$/u', $name);
    }
}

function getParam($key) {
    if (isset($_GET[$key])) return xssFilter($_GET[$key]);
    if (isset($_POST[$key])) return xssFilter($_POST[$key]);
    $json = json_decode(file_get_contents('php://input'), true);
    return json_last_error() === JSON_ERROR_NONE && isset($json[$key]) ? xssFilter($json[$key]) : null;
}

// 检查请求中是否包含禁用的参数
foreach ($config['disabled_params'] as $disabledParam) {
    if (getParam($disabledParam) !== null) {
        $err = $config['custom_errors']['param_disabled'] ?? ['code' => 404, 'msg' => '参数已被禁用'];
        jsonExit($err['code'], $err['msg']);
    }
}

// 检查是否有未知参数
$allowedParams = ['name', 'method', 'nolimit', 'mapping', 'debug'];
foreach (array_merge(array_keys($_GET), array_keys($_POST)) as $param) {
    if (!in_array($param, $allowedParams)) {
        $err = $config['custom_errors']['unknown_param'] ?? ['code' => 404, 'msg' => '未知参数'];
        jsonExit($err['code'], $err['msg']);
    }
}

function parseMapping($mappingStr, &$debugInfo = null) {
    if (empty($mappingStr)) return null;
    $mappingStr = trim($mappingStr);
    // 关键修复：HTML实体解码
    $decoded = html_entity_decode($mappingStr, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    if ($debugInfo !== null) {
        $debugInfo['mapping_raw'] = $mappingStr;
        $debugInfo['mapping_decoded'] = $decoded;
    }
    $mapping = json_decode($decoded, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        if ($debugInfo !== null) $debugInfo['mapping_error'] = json_last_error_msg();
        return ['error' => 'mapping参数JSON格式错误: ' . json_last_error_msg()];
    }
    if (!is_array($mapping)) return ['error' => 'mapping参数必须是JSON对象/数组格式'];
    $validGenders = ['male', 'female'];
    $validated = [];
    foreach ($mapping as $name => $rule) {
        if (!preg_match('/^[\x{4e00}-\x{9fa5}]+$/u', $name))
            return ['error' => "映射键名「{$name}」必须是纯中文字符"];
        if (is_array($rule) && isset($rule['gender'])) {
            $gender = strtolower($rule['gender']);
            $minProb = isset($rule['min']) ? floatval($rule['min']) : 0.5;
            $maxProb = isset($rule['max']) ? floatval($rule['max']) : 1.0;
        } elseif (is_array($rule) && count($rule) >= 2) {
            $gender = strtolower($rule[0]);
            $minProb = isset($rule[1]) ? floatval($rule[1]) : 0.5;
            $maxProb = isset($rule[2]) ? floatval($rule[2]) : 1.0;
        } else {
            return ['error' => "映射「{$name}」规则格式无效，必须是对象或索引数组"];
        }
        if (!in_array($gender, $validGenders))
            return ['error' => "映射「{$name}」性别必须是 male 或 female，当前值: {$gender}"];
        if ($minProb < 0.5 || $minProb > 1 || $maxProb < 0.5 || $maxProb > 1)
            return ['error' => "映射「{$name}」概率范围必须在 0.5~1 之间"];
        if ($minProb > $maxProb)
            return ['error' => "映射「{$name}」最小概率不能大于最大概率"];
        $validated[$name] = ['gender' => $gender, 'min_prob' => $minProb, 'max_prob' => $maxProb];
    }
    if ($debugInfo !== null) $debugInfo['mapping_parsed'] = $validated;
    return $validated;
}

function checkMapping($name, $mapping, &$debugInfo = null) {
    if (empty($mapping) || !is_array($mapping)) return null;
    foreach ($mapping as $mapName => $rule) {
        if ($mapName === $name) {
            $random = mt_rand() / mt_getrandmax();
            $prob = $rule['min_prob'] + $random * ($rule['max_prob'] - $rule['min_prob']);
            $prob = round($prob, 6);
            if ($debugInfo !== null) {
                $debugInfo['mapping_hit'] = [
                    'name' => $name,
                    'rule' => $rule,
                    'random_value' => $random,
                    'generated_prob' => $prob
                ];
            }
            return ['gender' => $rule['gender'], 'final_prob' => $prob, 'ismodified' => true];
        }
    }
    return null;
}

function loadJsonData() {
    if (!file_exists(JSON_FILE_PATH)) jsonExit(500, '未找到charfreq.json');
    if (!is_readable(JSON_FILE_PATH)) jsonExit(500, 'charfreq.json不可读');
    $content = file_get_contents(JSON_FILE_PATH);
    $charFreq = json_decode($content, true);
    if (json_last_error() !== JSON_ERROR_NONE) jsonExit(500, 'charfreq.json解析失败');
    if (!is_array($charFreq) || empty($charFreq)) jsonExit(500, 'JSON非有效字典格式');
    $maleTotal = $femaleTotal = 0;
    foreach ($charFreq as $char => $data) {
        if (!isset($data['male'], $data['female']) || !is_numeric($data['male']) || !is_numeric($data['female']))
            jsonExit(500, "字符【{$char}】格式错误，需包含male/female数字");
        $maleTotal += (int)$data['male'];
        $femaleTotal += (int)$data['female'];
    }
    if ($maleTotal === 0 || $femaleTotal === 0) jsonExit(500, '频次数据异常');
    return ['charFreq'=>$charFreq, 'maleTotal'=>$maleTotal, 'femaleTotal'=>$femaleTotal];
}

function loadTipsData() {
    if (!file_exists(TIPS_JSON_FILE_PATH)) jsonExit(500, '未找到tips.json');
    if (!is_readable(TIPS_JSON_FILE_PATH)) jsonExit(500, 'tips.json不可读');
    $content = file_get_contents(TIPS_JSON_FILE_PATH);
    $tipsData = json_decode($content, true);
    if (json_last_error() !== JSON_ERROR_NONE) jsonExit(500, 'tips.json解析失败');
    $requiredKeys = ['male_sure', 'male_uncertain', 'male_reverse', 'female_sure', 'female_uncertain', 'female_reverse'];
    foreach ($requiredKeys as $key) {
        if (!isset($tipsData[$key]) || !is_array($tipsData[$key]) || empty($tipsData[$key]))
            jsonExit(500, "tips.json缺少有效分区【{$key}】");
    }
    return $tipsData;
}

function getRandomTip($prob, $final_gender, $tipsData) {
    $final_gender = strtolower(trim($final_gender));
    if (!in_array($final_gender, ['male', 'female'])) $final_gender = 'male';
    $g_cn = $final_gender === 'male' ? '男' : '女';
    $rg_cn = $final_gender === 'male' ? '女' : '男';
    if ($prob > 0.6) $level = 'sure';
    elseif ($prob >= 0.4) $level = 'uncertain';
    else $level = 'reverse';
    $tipKey = $final_gender . '_' . $level;
    if (!isset($tipsData[$tipKey]) || empty($tipsData[$tipKey])) $tipKey = $final_gender . '_sure';
    $tipList = $tipsData[$tipKey];
    $randomTip = $tipList[array_rand($tipList)];
    return str_replace(['{targetG}', '{targetRG}'], [$g_cn, $rg_cn], $randomTip);
}

class NGender {
    private $charFreq, $maleTotal, $femaleTotal, $baseMale, $baseFemale;
    public function __construct($cf, $mt, $ft, $bm, $bf) {
        $this->charFreq = $cf; $this->maleTotal = $mt; $this->femaleTotal = $ft;
        $this->baseMale = $bm; $this->baseFemale = $bf;
    }
    private function calcProb($name, $g, &$debugInfo = null) {
        $prob = log($g === 'male' ? $this->baseMale : $this->baseFemale);
        $total = $g === 'male' ? $this->maleTotal : $this->femaleTotal;
        $charLogs = [];
        for ($i=0; $i<mb_strlen($name, 'UTF-8'); $i++) {
            $c = mb_substr($name, $i, 1, 'UTF-8');
            $cnt = isset($this->charFreq[$c]) ? $this->charFreq[$c] : ['male'=>1, 'female'=>1];
            $p = ($g === 'male' ? $cnt['male'] : $cnt['female']) / $total;
            $logP = log($p <= 0 ? 1e-10 : $p);
            $prob += $logP;
            if ($debugInfo !== null) $charLogs[] = ['char'=>$c, 'count'=>($g==='male'?$cnt['male']:$cnt['female']), 'p'=>$p, 'logP'=>$logP];
        }
        if ($debugInfo !== null) $debugInfo['calc_prob'][$g] = ['prob_log'=>$prob, 'char_logs'=>$charLogs];
        return $prob;
    }
    public function guess($name, &$debugInfo = null) {
        $pM = $this->calcProb($name, 'male', $debugInfo);
        $pF = $this->calcProb($name, 'female', $debugInfo);
        $maxP = max($pM, $pF);
        $eM = exp($pM - $maxP);
        $eF = exp($pF - $maxP);
        $pMale = $eM / ($eM + $eF);
        $pFemale = 1 - $pMale;
        if ($debugInfo !== null) {
            $debugInfo['normal'] = [
                'pM_log' => $pM, 'pF_log' => $pF, 'maxP' => $maxP,
                'eM' => $eM, 'eF' => $eF, 'pMale' => $pMale, 'pFemale' => $pFemale
            ];
        }
        return [
            'gender' => $pMale > $pFemale ? 'male' : 'female',
            'final_prob' => $pMale > $pFemale ? round($pMale, 6) : round($pFemale, 6)
        ];
    }
    public function guessOpposite($name, &$debugInfo = null) {
        $pM = $this->calcProb($name, 'male', $debugInfo);
        $pF = $this->calcProb($name, 'female', $debugInfo);
        $maxP = max($pM, $pF);
        $eM = exp($pM - $maxP);
        $eF = exp($pF - $maxP);
        $pMale = $eM / ($eM + $eF);
        $pFemale = 1 - $pMale;
        $swapMale = $pFemale;
        $swapFemale = $pMale;
        if ($debugInfo !== null) {
            $debugInfo['opposite'] = [
                'original_pMale' => $pMale, 'original_pFemale' => $pFemale,
                'swap_pMale' => $swapMale, 'swap_pFemale' => $swapFemale
            ];
        }
        return [
            'gender' => $swapMale > $swapFemale ? 'male' : 'female',
            'final_prob' => $swapMale > $swapFemale ? round($swapMale, 6) : round($swapFemale, 6)
        ];
    }
    public function guessRandom($name, &$debugInfo = null) {
        $genderRand = mt_rand(0, 1);
        $gender = $genderRand === 0 ? 'male' : 'female';
        $randVal = mt_rand() / mt_getrandmax();
        $prob = 0.5 + $randVal * 0.5;
        $prob = round($prob, 6);
        if ($debugInfo !== null) {
            $debugInfo['random'] = [
                'gender_rand' => $genderRand, 'gender' => $gender,
                'prob_rand' => $randVal, 'final_prob' => $prob
            ];
        }
        return ['gender' => $gender, 'final_prob' => $prob];
    }
}

// ========== 主逻辑 ==========
$name = getParam('name');
$nolimit = getParam('nolimit');
$method = isset($_GET['method']) || isset($_POST['method']) ? (int)getParam('method') : METHOD_NORMAL;
$mappingStr = getParam('mapping');
$debug = isset($_GET['debug']) ? (int)getParam('debug') : 0;

if (!in_array($method, [METHOD_NORMAL, METHOD_REVERSE, METHOD_OPPOSITE, METHOD_RANDOM])) $method = METHOD_NORMAL;

// 模式限制检查
if ($config['restrict_modes'] && $method !== METHOD_NORMAL) {
    $err = $config['custom_errors']['mode_restricted'] ?? ['code' => 403, 'msg' => '当前模式已被限制'];
    jsonExit($err['code'], $err['msg']);
}

// 黑名单检查
if (in_array($name, $config['blacklist'])) {
    $err = $config['custom_errors']['name_blacklisted'] ?? ['code' => 403, 'msg' => '姓名在黑名单中'];
    jsonExit($err['code'], $err['msg']);
}

// 强绑定映射检查（优先级最高）
if (isset($config['force_mapping'][$name])) {
    $rule = $config['force_mapping'][$name];
    if (isset($rule['gender'])) {
        $gender = strtolower($rule['gender']);
        $minProb = isset($rule['min']) ? floatval($rule['min']) : 0.5;
        $maxProb = isset($rule['max']) ? floatval($rule['max']) : 1.0;
    } elseif (is_array($rule) && count($rule) >= 2) {
        $gender = strtolower($rule[0]);
        $minProb = isset($rule[1]) ? floatval($rule[1]) : 0.5;
        $maxProb = isset($rule[2]) ? floatval($rule[2]) : 1.0;
    } else {
        jsonExit(500, '强绑定映射格式错误');
    }
    $random = mt_rand() / mt_getrandmax();
    $prob = $minProb + $random * ($maxProb - $minProb);
    $prob = round($prob, 6);
    $tipsData = loadTipsData();
    $responseData = [
        'name' => $name,
        'gender' => $gender,
        'gender_cn' => $gender === 'male' ? '男' : '女',
        'probability' => $prob,
        'fun_tip' => getRandomTip($prob, $gender, $tipsData),
        'nolimit_used' => in_array(strtolower((string)$nolimit), ['true', '1', 'yes', 'on']),
        'mode' => $method,
        'ismodified' => true,
        'force_mapped' => true
    ];
    jsonExit(200, '查询成功', $responseData);
}

$jsonData = loadJsonData();
$tipsData = loadTipsData();
$ngender = new NGender($jsonData['charFreq'], $jsonData['maleTotal'], $jsonData['femaleTotal'], BASE_MALE, BASE_FEMALE);

$debugInfo = ($debug == 1) ? ['request' => ['name'=>$name, 'method'=>$method, 'mapping'=>$mappingStr]] : null;

$mapping = null;
if (!empty($mappingStr)) {
    $parsed = parseMapping($mappingStr, $debugInfo);
    if (isset($parsed['error'])) {
        if ($debugInfo !== null) {
            $debugInfo['error'] = $parsed['error'];
            jsonExit(400, $parsed['error'], ['debug_info' => $debugInfo]);
        } else {
            jsonExit(400, $parsed['error']);
        }
    }
    $mapping = $parsed;
}

$isNoLimit = in_array(strtolower((string)$nolimit), ['true', '1', 'yes', 'on']);
if (is_null($name) || $name === '') jsonExit(400, '缺少参数name');
if (!checkName($name, !$isNoLimit)) {
    $errorMsg = $isNoLimit ? '姓名必须是纯中文字符（无字数限制）' : '姓名必须是2-4个纯中文字符';
    jsonExit(400, $errorMsg);
}

$adjusted = null;
// 优先映射表
$mapped = checkMapping($name, $mapping, $debugInfo);
if ($mapped) {
    $adjusted = [
        'gender' => $mapped['gender'],
        'final_prob' => $mapped['final_prob'],
        'method' => $method,
        'method_label' => METHOD_LABELS[$method],
        'ismodified' => true
    ];
} else {
    switch ($method) {
        case METHOD_NORMAL:
            $res = $ngender->guess($name, $debugInfo);
            if ($debugInfo) $debugInfo['mode'] = 'normal';
            break;
        case METHOD_REVERSE:
            $res = $ngender->guess($name, $debugInfo);
            $prob = 1 - $res['final_prob'];
            $gender = $res['gender'] === 'male' ? 'female' : 'male';
            if ($prob > 0.4) $prob = 0.4 - ($prob - 0.4);
            $prob = round(max(0, min(1, $prob)), 6);
            if ($debugInfo) {
                $debugInfo['reverse'] = [
                    'original_gender' => $res['gender'],
                    'original_prob' => $res['final_prob'],
                    'new_prob' => $prob,
                    'new_gender' => $gender
                ];
                $debugInfo['mode'] = 'reverse';
            }
            $res = ['gender' => $gender, 'final_prob' => $prob];
            break;
        case METHOD_OPPOSITE:
            $res = $ngender->guessOpposite($name, $debugInfo);
            if ($debugInfo) $debugInfo['mode'] = 'opposite';
            break;
        case METHOD_RANDOM:
            $res = $ngender->guessRandom($name, $debugInfo);
            if ($debugInfo) $debugInfo['mode'] = 'random';
            break;
        default:
            $res = $ngender->guess($name, $debugInfo);
            $method = METHOD_NORMAL;
            if ($debugInfo) $debugInfo['mode'] = 'normal (default)';
    }
    $adjusted = [
        'gender' => $res['gender'],
        'final_prob' => $res['final_prob'],
        'method' => $method,
        'method_label' => METHOD_LABELS[$method],
        'ismodified' => false
    ];
}

$gCn = $adjusted['gender'] === 'male' ? '男' : '女';
$responseData = [
    'name' => $name,
    'gender' => $adjusted['gender'],
    'gender_cn' => $gCn,
    'probability' => $adjusted['final_prob'],
    'fun_tip' => getRandomTip($adjusted['final_prob'], $adjusted['gender'], $tipsData),
    'nolimit_used' => $isNoLimit,
    'mode' => $adjusted['method'],
    'ismodified' => $adjusted['ismodified']
];
if ($debugInfo !== null) $responseData['debug_info'] = $debugInfo;

jsonExit(200, '查询成功', $responseData);
?>