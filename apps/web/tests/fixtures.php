<?php
declare(strict_types=1);
// Only invoked by the browser test runner over a private stdin pipe.
require dirname(__DIR__).'/vendor/autoload.php';
if (getenv('APP_ENV') !== 'test' || getenv('RUN_DATABASE_TESTS') !== '1') { throw new RuntimeException('Test environment required.'); }
$kernel = new App\Kernel('test', false); $kernel->boot();
$db = $kernel->getContainer()->get('doctrine')->getConnection();
if ($db->fetchOne('SELECT current_database()') !== 'igreja_test' || !str_contains((string) getenv('DATABASE_URL'), '@postgres-test:5432/igreja_test?')) { throw new RuntimeException('Isolated test database required.'); }
$input = json_decode(stream_get_contents(STDIN), true, 16, JSON_THROW_ON_ERROR);
if (($input['action'] ?? '') === 'expired_token') {
    $id=(int)$input['user']; $session=$db->fetchOne('SELECT id FROM auth_sessions WHERE user_id = ? AND revoked_at IS NULL ORDER BY created_at DESC LIMIT 1',[$id]);
    echo json_encode(['token'=>Firebase\JWT\JWT::encode(['sub'=>(string)$id,'sid'=>$session,'iat'=>time()-660,'exp'=>time()-60,'iss'=>getenv('JWT_ISSUER'),'aud'=>getenv('JWT_AUDIENCE')],file_get_contents(getenv('JWT_PRIVATE_KEY_PATH')),'RS256')],JSON_THROW_ON_ERROR); exit;
}
if (($input['action'] ?? '') === 'revoke') {
    $db->executeStatement("UPDATE user_ministries SET status='INACTIVE',is_leader=FALSE,left_at=CURRENT_TIMESTAMP,updated_at=CURRENT_TIMESTAMP WHERE user_id=? AND ministry_id=?",[(int)$input['user'],(int)$input['ministry']]); echo '{}'; exit;
}
if (($input['action'] ?? '') !== 'seed' || !is_string($input['password']) || strlen($input['password'])<12) { throw new RuntimeException('Invalid fixture request.'); }
$tag=bin2hex(random_bytes(5)); $users=[];
foreach (['admin'=>'ADMIN','pastor'=>'PASTOR','leader'=>'LEADER','member'=>'MEMBER','outsider'=>'MEMBER'] as $key=>$role) {
    $email="web-$key-$tag@e2e.test";
    $id=(int)$db->fetchOne("INSERT INTO users(name,email,email_normalized,password_hash,role,status,created_at,updated_at) VALUES (?,?,?, ?,?,'ACTIVE',CURRENT_TIMESTAMP,CURRENT_TIMESTAMP) RETURNING id",["Teste $key",$email,$email,password_hash($input['password'],PASSWORD_BCRYPT,['cost'=>4]),$role]);
    $users[$key]=['id'=>$id,'email'=>$email];
}
$ministry=(int)$db->fetchOne("INSERT INTO ministries(name,slug,description,status,created_at,updated_at) VALUES ('Louvor de teste',?,'Servir juntos.','ACTIVE',CURRENT_TIMESTAMP,CURRENT_TIMESTAMP) RETURNING id",["web-louvor-$tag"]);
foreach (['leader','member'] as $key) { $db->executeStatement("INSERT INTO user_ministries(user_id,ministry_id,is_leader,status,joined_at,created_at,updated_at) VALUES (?,?,?,'ACTIVE',CURRENT_TIMESTAMP,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP)",[$users[$key]['id'],$ministry,$key==='leader'?'true':'false']); }
for ($i=1;$i<=13;$i++) $db->executeStatement("INSERT INTO posts(author_id,title,content,visibility,comments_enabled,status,published_at,created_at,updated_at) VALUES (?,?,?,'PUBLIC',TRUE,'PUBLISHED',date_trunc('second',clock_timestamp()),CURRENT_TIMESTAMP,CURRENT_TIMESTAMP)",[$users['admin']['id'],"Novidade da comunidade $i","Texto público $i."]);
$private=(int)$db->fetchOne("INSERT INTO posts(author_id,ministry_id,title,content,visibility,comments_enabled,status,published_at,created_at,updated_at) VALUES (?,?,'Encontro reservado','Assunto reservado dos integrantes','MINISTRY_MEMBERS',TRUE,'PUBLISHED',date_trunc('second',clock_timestamp()),CURRENT_TIMESTAMP,CURRENT_TIMESTAMP) RETURNING id",[$users['admin']['id'],$ministry]);
$db->executeStatement("INSERT INTO events(created_by,title,description,starts_at,visibility,status,created_at,updated_at) VALUES (?,'Encontro de domingo','Nossa comunidade reunida.',CURRENT_TIMESTAMP + interval '7 days','PUBLIC','PUBLISHED',CURRENT_TIMESTAMP,CURRENT_TIMESTAMP)",[$users['admin']['id']]);
echo json_encode(['users'=>$users,'ministry'=>$ministry,'privatePost'=>$private,'tag'=>$tag],JSON_THROW_ON_ERROR);
