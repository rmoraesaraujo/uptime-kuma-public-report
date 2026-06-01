# Relatorio Publico de Incidentes para Uptime Kuma

Aplicacao web simples em PHP para exibir estatisticas publicas de incidentes do Uptime Kuma sem modificar o codigo original do Kuma.

O painel le o SQLite do Uptime Kuma em modo somente leitura, calcula incidentes por transicao `UP -> DOWN`, evita contar heartbeats `DOWN` consecutivos como incidentes separados e cacheia o resultado por 60 segundos por padrao.

## Recursos

- Detecta automaticamente o arquivo SQLite dentro de `/opt/uptime-kuma/data`.
- Abre o SQLite com `mode=ro` e `PRAGMA query_only = ON`.
- Detecta tabelas e colunas por `sqlite_master` e `PRAGMA table_info`.
- Mostra incidentes hoje, ultimos 7 dias e ultimos 30 dias.
- Mostra downtime total por periodo filtrado.
- Mostra uptime percentual diario, semanal e mensal.
- Lista incidentes por monitor.
- Agrupa os cards por grupo de monitor do Uptime Kuma, quando a instalacao usa monitores do tipo `group`.
- Filtros por monitor, periodo e status atual.
- Layout responsivo com visual de status page profissional.
- Nao exibe URLs, tokens, senhas, headers, certificados ou configuracoes internas.
- Endpoint JSON em `/api/stats`.

## Estrutura

```text
.
├── Dockerfile
├── docker-compose.yml
├── nginx/
│   └── relatorio.dominio.com.conf
├── public/
│   ├── index.php
│   ├── router.php
│   └── assets/
│       ├── app.js
│       └── styles.css
└── src/
    ├── Config.php
    ├── DatabaseLocator.php
    ├── FileCache.php
    ├── SchemaDetector.php
    ├── SqliteConnectionFactory.php
    └── StatsService.php
```

## Instalar na VPS Ubuntu com Docker

1. Copie este projeto para a VPS, por exemplo:

```bash
sudo mkdir -p /opt/uptime-kuma-public-report
sudo chown -R "$USER":"$USER" /opt/uptime-kuma-public-report
cd /opt/uptime-kuma-public-report
```

2. Coloque os arquivos deste projeto nessa pasta.

3. Confirme que o Uptime Kuma usa o volume esperado:

```bash
ls -lah /opt/uptime-kuma/data
```

4. Suba o painel:

```bash
docker compose up -d --build
```

5. Teste localmente na VPS:

```bash
curl -I http://127.0.0.1:8088
```

Por seguranca, o `docker-compose.yml` publica a aplicacao apenas em `127.0.0.1:8088`. Use Nginx, aaPanel ou outro proxy reverso para expor o dominio publico.

## Variaveis de ambiente

As variaveis ficam em `docker-compose.yml`.

| Variavel | Padrao | Descricao |
| --- | --- | --- |
| `DATA_PATH` | `/kuma-data` | Caminho dentro do container onde o volume do Uptime Kuma sera montado. |
| `SQLITE_PATH` | vazio | Opcional. Caminho exato do SQLite se voce nao quiser autodeteccao. |
| `CACHE_TTL` | `60` | Tempo de cache em segundos. Use `30` ou `60` para evitar leitura excessiva. |
| `CACHE_PATH` | `/tmp/uptime-kuma-public-report-cache` | Pasta de cache no container. |
| `APP_TIMEZONE` | `America/Sao_Paulo` | Timezone exibido na pagina. |
| `DB_TIMEZONE` | `UTC` | Timezone usado ao interpretar datas sem timezone no banco. |
| `PUBLIC_TITLE` | `Relatorio de Incidentes` | Titulo publico da pagina. |
| `SQLITE_IMMUTABLE` | `false` | Opcional. Use `true` apenas se sua leitura read-only tiver problema de lock/WAL e voce aceitar leituras por snapshot. |

## Publicar em relatorio.dominio.com com Nginx

Arquivo exemplo: `nginx/relatorio.dominio.com.conf`.

```nginx
server {
    listen 80;
    listen [::]:80;
    server_name relatorio.dominio.com;

    location / {
        proxy_pass http://127.0.0.1:8088;
        proxy_http_version 1.1;

        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
    }
}
```

Com Certbot:

```bash
sudo cp nginx/relatorio.dominio.com.conf /etc/nginx/sites-available/relatorio.dominio.com
sudo ln -s /etc/nginx/sites-available/relatorio.dominio.com /etc/nginx/sites-enabled/
sudo nginx -t
sudo systemctl reload nginx
sudo certbot --nginx -d relatorio.dominio.com
```

## Publicar pelo aaPanel

1. Crie um site para `relatorio.dominio.com`.
2. Em proxy reverso, aponte para:

```text
http://127.0.0.1:8088
```

3. Adicione os headers de proxy, se o aaPanel permitir:

```nginx
proxy_set_header Host $host;
proxy_set_header X-Real-IP $remote_addr;
proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
proxy_set_header X-Forwarded-Proto $scheme;
```

4. Ative SSL pelo painel.

## Como os dados sao calculados

### Deteccao do banco

`DatabaseLocator` percorre `DATA_PATH`, procura arquivos `.db`, `.sqlite` e `.sqlite3`, valida o cabecalho `SQLite format 3` e prioriza nomes como `kuma.db`.

### Deteccao de schema

`SchemaDetector` usa:

```sql
SELECT name
FROM sqlite_master
WHERE type = 'table'
  AND name NOT LIKE 'sqlite_%';
```

Depois usa:

```sql
PRAGMA table_info("nome_da_tabela");
```

A tabela de heartbeats e reconhecida por colunas equivalentes a `monitor_id`, `status` e `time`. A tabela de monitores e reconhecida por `id` e `name`.

### Consulta de heartbeats

Quando disponivel, o app usa `LAG()` para reduzir heartbeats consecutivos do mesmo status:

```sql
WITH ordered AS (
    SELECT
        monitor_id,
        status,
        time,
        LAG(status) OVER (
            PARTITION BY monitor_id
            ORDER BY time ASC, id ASC
        ) AS previous_status
    FROM heartbeat
    WHERE monitor_id IN (...)
      AND datetime(time) >= datetime(:from)
)
SELECT monitor_id, status, time
FROM ordered
WHERE previous_status IS NULL
   OR CAST(status AS TEXT) <> CAST(previous_status AS TEXT);
```

Tambem e buscado o ultimo heartbeat anterior ao inicio do periodo para saber se o monitor ja estava `DOWN` antes da janela. Isso evita contar falso incidente quando o periodo comeca com o monitor ja fora.

### Regra de incidente

Um incidente e contado somente quando:

```text
status anterior normalizado = UP
novo status normalizado = DOWN
```

Assim, varios heartbeats `DOWN` consecutivos representam um unico incidente.

### Downtime

O downtime e a soma dos intervalos em que o estado consolidado do monitor ficou `DOWN`, sempre limitado ao periodo escolhido. Para o uptime agregado, o denominador e:

```text
duracao_do_periodo_em_segundos * quantidade_de_monitores_filtrados
```

## Seguranca

- O volume do Kuma e montado como somente leitura:

```yaml
volumes:
  - /opt/uptime-kuma/data:/kuma-data:ro
```

- O container tambem roda com `read_only: true`.
- A pagina seleciona apenas identificador, nome publico do monitor, status e horario de heartbeat.
- Nao ha exibicao de URL monitorada, hostname, token, senha, headers, certificados ou configuracoes internas.
- O app nao modifica o codigo nem o banco do Uptime Kuma.

## Problemas comuns

### Banco nao encontrado

Confira se o volume existe na VPS:

```bash
ls -lah /opt/uptime-kuma/data
```

Se o banco tiver outro nome, defina `SQLITE_PATH` no compose:

```yaml
environment:
  SQLITE_PATH: /kuma-data/kuma.db
```

### Horarios parecem deslocados

Uptime Kuma geralmente grava datas em UTC. Se a sua instalacao grava datas locais, ajuste:

```yaml
environment:
  DB_TIMEZONE: America/Sao_Paulo
```

### Erro de lock ou WAL em leitura read-only

Confirme que os arquivos `kuma.db-wal` e `kuma.db-shm`, se existirem, estao no mesmo volume. Se ainda houver problema, voce pode testar:

```yaml
environment:
  SQLITE_IMMUTABLE: "true"
```

Use essa opcao com cuidado: ela reduz locks, mas pode trabalhar com uma visao de snapshot do arquivo.
