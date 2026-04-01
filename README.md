# CDN Manager com frontend React e backend PHP

O projeto agora foi estruturado para hospedagem comum com PHP:

- `public/` e o diretorio publico do site.
- `.env` fica fora de `public/`, portanto nao fica acessivel pela web.
- `app/` contem o bootstrap e a API PHP.
- `public/cdn/` contem os arquivos publicados pelo gerenciador.

## Estrutura esperada

```text
projeto/
  .env
  app/
  public/
    api/
    build/
    cdn/
    .htaccess
    index.php
```

## Configuracao

1. Copie `.env.example` para `.env`.
2. Ajuste `ADMIN_USERNAME`, `ADMIN_PASSWORD` e `SESSION_SECRET`.
3. Aponte o document root da hospedagem para `public/`.

## Build do frontend

```bash
npm install
npm run build
```

## Arquivos para producao

Envie para o servidor exatamente estes itens:

### Fora da pasta publica

Estes arquivos e pastas devem ficar fora do web root:

```text
app/
app/bootstrap.php

.env
```

### Dentro da pasta publica do site

Estes arquivos e pastas devem ficar dentro da pasta publica da hospedagem (`public/` ou `public_html/`):

```text
public/.htaccess
public/index.php
public/api/
public/api/index.php
public/build/
public/build/index.html
public/build/.vite/manifest.json
public/build/assets/
public/cdn/
```

Observacoes:

- `public/cdn/` deve ser enviado se voce ja tiver arquivos publicados.
- Se `public/cdn/` estiver vazio, crie a pasta no servidor mesmo assim.
- Se a sua hospedagem usar `public_html`, coloque o conteudo de `public/` dentro de `public_html` e mantenha `app/` e `.env` um nivel acima.

### Nao enviar para producao

Nao precisa enviar estes itens para o servidor:

```text
src/
node_modules/
.vscode/
dist/

index.html
package.json
package-lock.json
tsconfig.json
vite.config.ts
.env.example
.env copy.example
README.md
metadata.json
```

## Teste local com PHP

```bash
php -S localhost:8000 -t public public/router.php
```

Depois acesse `http://localhost:8000`.
