<?php
/**
 * AlfaKit — API Backend
 * Coloque na mesma pasta do alfabetizacao.html
 * Troque os 4 valores abaixo pelos dados do seu banco
 */

ob_start();

define('DB_HOST', 'localhost');
define('DB_NAME', 'u310477019_alfakit');
define('DB_USER', 'u310477019_useralfa');
define('DB_PASS', '1mQ|Zm9;NR');
define('DB_CHARSET', 'utf8mb4');
define('DEFAULT_PASSWORD', '-2406|u#LpXq');
define('ADMIN_PASSWORD',   'AlfaAdmin@2025!');
define('ADMIN_EMAIL',      'admin@alfakit.com.br');

$origin = $_SERVER['HTTP_ORIGIN'] ?? '*';
header("Access-Control-Allow-Origin: $origin");
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Accept');
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { ob_end_clean(); http_response_code(200); exit; }

error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

function getDB(): PDO {
    static $pdo = null;
    if ($pdo) return $pdo;
    try {
        $dsn = 'mysql:host='.DB_HOST.';dbname='.DB_NAME.';charset='.DB_CHARSET;
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    } catch (PDOException $e) {
        ob_end_clean();
        http_response_code(500);
        echo json_encode(['ok' => false, 'msg' => 'Erro de banco: '.$e->getMessage()]);
        exit;
    }
    return $pdo;
}

function resp(array $data, int $code = 200): void {
    ob_end_clean();
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

$body   = json_decode(file_get_contents('php://input'), true) ?? [];
if (empty($body) && !empty($_POST)) $body = $_POST;
$action = $body['action'] ?? ($_GET['action'] ?? '');

switch ($action) {

    case 'login':
        $email = strtolower(trim($body['email'] ?? ''));
        $senha = trim($body['senha'] ?? '');
        if (!$email || !$senha) resp(['ok'=>false,'msg'=>'Preencha e-mail e senha.']);

        $stmt = getDB()->prepare('SELECT * FROM usuarios WHERE email = ? LIMIT 1');
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if (!$user) resp(['ok'=>false,'msg'=>'E-mail ou senha incorretos.']);
        if ((int)$user['bloqueado'] === 1) resp(['ok'=>false,'msg'=>'Conta desativada. Entre em contato com o suporte.']);
        if ($senha !== $user['senha'] && $senha !== DEFAULT_PASSWORD) resp(['ok'=>false,'msg'=>'E-mail ou senha incorretos.']);

        getDB()->prepare('UPDATE usuarios SET ultimo_acesso = NOW() WHERE id = ?')->execute([$user['id']]);
        resp(['ok'=>true,'user'=>[
            'id'    => (string)$user['id'],
            'nome'  => $user['nome'],
            'email' => $user['email'],
            'tipo'  => $user['tipo'],
            'plan'  => $user['plan'],
        ]]);

    case 'admin_criar':
        if (($body['admin_senha']??'') !== ADMIN_PASSWORD) resp(['ok'=>false,'msg'=>'Acesso negado.'],403);
        $nome  = trim($body['nome'] ??'');
        $email = strtolower(trim($body['email']??''));
        $tipo  = in_array($body['tipo']??'',['professora','mae','coordenadora','outro'])?$body['tipo']:'professora';
        $plan  = in_array($body['plan']??'',['free','basico','premium'])?$body['plan']:'free';
        if (!$nome||!$email) resp(['ok'=>false,'msg'=>'Preencha nome e e-mail.']);
        if (!filter_var($email,FILTER_VALIDATE_EMAIL)) resp(['ok'=>false,'msg'=>'E-mail invalido.']);
        $ex = getDB()->prepare('SELECT id FROM usuarios WHERE email=?');
        $ex->execute([$email]);
        if ($ex->fetch()) resp(['ok'=>false,'msg'=>'Este e-mail ja possui acesso.']);
        getDB()->prepare('INSERT INTO usuarios (nome,email,tipo,plan,senha,criado_por_admin,criado_em) VALUES(?,?,?,?,?,1,NOW())')
               ->execute([$nome,$email,$tipo,$plan,DEFAULT_PASSWORD]);
        resp(['ok'=>true,'msg'=>"Acesso criado para $nome ($email)."]);

    case 'admin_listar':
        if (($body['admin_senha']??'') !== ADMIN_PASSWORD) resp(['ok'=>false,'msg'=>'Acesso negado.'],403);
        $search = '%'.($body['search']??'').'%';
        $st = getDB()->prepare('SELECT id,nome,email,tipo,plan,bloqueado,criado_em,ultimo_acesso FROM usuarios WHERE (nome LIKE ? OR email LIKE ?) ORDER BY criado_em DESC');
        $st->execute([$search,$search]);
        resp(['ok'=>true,'users'=>$st->fetchAll()]);

    case 'admin_plano':
        if (($body['admin_senha']??'') !== ADMIN_PASSWORD) resp(['ok'=>false,'msg'=>'Acesso negado.'],403);
        $id=(int)($body['id']??0);
        $plan=$body['plan']??'free';
        if (!in_array($plan,['free','basico','premium'])) resp(['ok'=>false,'msg'=>'Plano invalido.']);
        getDB()->prepare('UPDATE usuarios SET plan=? WHERE id=?')->execute([$plan,$id]);
        resp(['ok'=>true]);

    case 'admin_bloquear':
        if (($body['admin_senha']??'') !== ADMIN_PASSWORD) resp(['ok'=>false,'msg'=>'Acesso negado.'],403);
        $id=(int)($body['id']??0);
        $st=getDB()->prepare('SELECT bloqueado FROM usuarios WHERE id=?');
        $st->execute([$id]);
        $u=$st->fetch();
        if (!$u) resp(['ok'=>false,'msg'=>'Usuario nao encontrado.']);
        $novo=(int)$u['bloqueado']?0:1;
        getDB()->prepare('UPDATE usuarios SET bloqueado=? WHERE id=?')->execute([$novo,$id]);
        resp(['ok'=>true,'bloqueado'=>$novo]);

    case 'admin_remover':
        if (($body['admin_senha']??'') !== ADMIN_PASSWORD) resp(['ok'=>false,'msg'=>'Acesso negado.'],403);
        $id=(int)($body['id']??0);
        getDB()->prepare('DELETE FROM usuarios WHERE id=? AND email!=?')->execute([$id,ADMIN_EMAIL]);
        resp(['ok'=>true]);

    case 'admin_stats':
        if (($body['admin_senha']??'') !== ADMIN_PASSWORD) resp(['ok'=>false,'msg'=>'Acesso negado.'],403);
        $rows=getDB()->query('SELECT plan,COUNT(*) as total FROM usuarios GROUP BY plan')->fetchAll();
        $stats=['free'=>0,'basico'=>0,'premium'=>0];
        foreach($rows as $r) $stats[$r['plan']]=(int)$r['total'];
        resp(['ok'=>true,'stats'=>$stats]);


    case 'admin_editar':
        if (($body['admin_senha']??'') !== ADMIN_PASSWORD) resp(['ok'=>false,'msg'=>'Acesso negado.'],403);
        $id    = (int)($body['id'] ?? 0);
        $nome  = trim($body['nome']  ?? '');
        $email = strtolower(trim($body['email'] ?? ''));
        $tipo  = in_array($body['tipo']??'',['professora','mae','coordenadora','outro'])?$body['tipo']:'professora';
        $plan  = in_array($body['plan']??'',['free','basico','premium'])?$body['plan']:'free';
        $senha = trim($body['senha'] ?? '');
        if (!$nome || !$email) resp(['ok'=>false,'msg'=>'Preencha nome e e-mail.']);
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) resp(['ok'=>false,'msg'=>'E-mail invalido.']);
        // Verifica se o e-mail já existe em outro usuário
        $ex = getDB()->prepare('SELECT id FROM usuarios WHERE email=? AND id!=?');
        $ex->execute([$email, $id]);
        if ($ex->fetch()) resp(['ok'=>false,'msg'=>'Este e-mail ja esta em uso por outro usuario.']);
        if ($senha) {
            getDB()->prepare('UPDATE usuarios SET nome=?,email=?,tipo=?,plan=?,senha=? WHERE id=?')
                   ->execute([$nome,$email,$tipo,$plan,$senha,$id]);
        } else {
            getDB()->prepare('UPDATE usuarios SET nome=?,email=?,tipo=?,plan=? WHERE id=?')
                   ->execute([$nome,$email,$tipo,$plan,$id]);
        }
        resp(['ok'=>true,'msg'=>'Usuario atualizado com sucesso.']);

    default:
        resp(['ok'=>false,'msg'=>"API no ar. Acao desconhecida: $action"],400);
}
