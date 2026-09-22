# Operação, backup e recuperação

O responsável operacional é o proprietário da instalação. A referência inicial é
backup diário, retenção local de 14 dias, RPO de 24 horas e RTO de 4 horas. Revise
esses valores quando a quantidade de dados e membros crescer. Uma cópia deve sair
do computador da aplicação para armazenamento criptografado com acesso separado.

## Rotina diária

Execute na raiz do projeto:

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File scripts/backup.ps1
powershell -NoProfile -ExecutionPolicy Bypass -File scripts/operations-check.ps1
```

`backup.ps1` gera um dump PostgreSQL consistente, arquiva os uploads, valida os
dois arquivos, calcula SHA-256 e grava `manifest.json`. A pasta `backups/` contém
dados privados, fica fora do Git e não substitui uma cópia externa.

No Agendador de Tarefas do Windows, configure o diretório inicial como a raiz do
projeto e execute o backup uma vez ao dia. A instalação automática cria o backup
às 03:00 e a verificação às 03:30 para o usuário atual:

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File scripts/install-scheduled-tasks.ps1
```

As tarefas usam a sessão do usuário e exigem Docker Desktop iniciado. Confira
diariamente o último resultado no Agendador e registre falhas em um canal acompanhado
pelo responsável.

## Teste mensal de recuperação

Escolha um diretório produzido pelo backup e execute:

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File scripts/verify-restore.ps1 -BackupDirectory backups/20260922T120000Z
```

O script confere checksums, restaura em um banco temporário cujo nome começa com
`igreja_restore_check_`, verifica tabelas e migrations, testa o arquivo de uploads
e remove o banco temporário. Ele nunca sobrescreve o banco principal ou o volume
de uploads.

Chaves JWT, `.env`, token do Cloudflare e credenciais Expo devem ter cópia
criptografada e separada. Não os inclua no dump nem envie a repositórios. Após
perda de credencial, gere um novo valor, atualize o serviço correspondente e
revogue o anterior.

Antes de migrations, atualizações de imagem ou manutenção relevante, gere um
backup e confirme `OPERATIONS_OK`. Depois da implantação, valide `/api/health`,
`/api/ready`, login, uma lista privada e os logs dos containers.
