<?php
/**
 * Gerador de anúncios de afiliado (Shopee + Mercado Livre) — MVP
 * Rodar:  php -S localhost:8000   (dentro desta pasta)  →  http://localhost:8000
 * Requer: PHP 8+ com extensão cURL.
 */
declare(strict_types=1);

const UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36';

function http_get(string $url, array $headers = []): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 8,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_ENCODING       => '',
        CURLOPT_USERAGENT      => UA,
        CURLOPT_COOKIEJAR      => sys_get_temp_dir() . '/afil_cookies.txt',
        CURLOPT_COOKIEFILE     => sys_get_temp_dir() . '/afil_cookies.txt',
        CURLOPT_HTTPHEADER     => array_merge([
            'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,*/*;q=0.8',
            'Accept-Language: pt-BR,pt;q=0.9,en;q=0.6',
        ], $headers),
    ]);
    $body = curl_exec($ch);
    $res = [
        'body'  => is_string($body) ? $body : '',
        'url'   => (string) curl_getinfo($ch, CURLINFO_EFFECTIVE_URL),
        'code'  => (int) curl_getinfo($ch, CURLINFO_HTTP_CODE),
        'type'  => (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE),
        'error' => curl_error($ch),
    ];
    curl_close($ch);
    return $res;
}

function json_out(array $data): never
{
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function url_publica(string $url): bool
{
    $p = parse_url($url);
    if (!$p || !in_array($p['scheme'] ?? '', ['http', 'https'], true) || empty($p['host'])) return false;
    $ip = gethostbyname($p['host']);
    return (bool) filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
}

function absolutizar(string $u, string $base): string
{
    if (str_starts_with($u, '//')) return 'https:' . $u;
    if (preg_match('#^https?://#i', $u)) return $u;
    $p = parse_url($base);
    return ($p['scheme'] ?? 'https') . '://' . ($p['host'] ?? '') . '/' . ltrim($u, '/');
}

/** Lê todas as <meta> e devolve [chave => conteúdo] */
function ler_metas(string $html): array
{
    $metas = [];
    if (!preg_match_all('/<meta\s[^>]*>/i', $html, $m)) return $metas;
    foreach ($m[0] as $tag) {
        preg_match_all('/([a-zA-Z:_-]+)\s*=\s*(?:"([^"]*)"|\'([^\']*)\')/', $tag, $a, PREG_SET_ORDER);
        $at = [];
        foreach ($a as $x) $at[strtolower($x[1])] = html_entity_decode($x[3] ?? $x[2], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $key = $at['property'] ?? $at['name'] ?? $at['itemprop'] ?? null;
        if ($key && isset($at['content']) && !isset($metas[strtolower($key)])) $metas[strtolower($key)] = $at['content'];
    }
    return $metas;
}

/** Procura um objeto Product nos blocos JSON-LD */
function achar_produto(mixed $no): ?array
{
    if (!is_array($no)) return null;
    $tipo = $no['@type'] ?? null;
    if ($tipo === 'Product' || (is_array($tipo) && in_array('Product', $tipo, true))) return $no;
    foreach ($no as $filho) {
        if (is_array($filho) && ($r = achar_produto($filho))) return $r;
    }
    return null;
}

function ler_jsonld(string $html): ?array
{
    if (!preg_match_all('#<script[^>]+type=["\']application/ld\+json["\'][^>]*>(.*?)</script>#is', $html, $m)) return null;
    foreach ($m[1] as $bloco) {
        $j = json_decode(trim(html_entity_decode($bloco, ENT_QUOTES | ENT_HTML5, 'UTF-8')), true);
        if ($j && ($p = achar_produto($j))) return $p;
    }
    return null;
}

function num(mixed $v): ?float
{
    if ($v === null || $v === '') return null;
    if (is_numeric($v)) return (float) $v;
    $s = preg_replace('/[^\d,.]/', '', (string) $v);
    if ($s === '' || $s === null) return null;
    if (str_contains($s, ',')) $s = str_replace(['.', ','], ['', '.'], $s);
    return is_numeric($s) ? (float) $s : null;
}

function limpar_titulo(string $t): string
{
    $t = preg_replace('/\s*[|\-–—]\s*(Shopee Brasil|Shopee|Mercado\s?Livre).*$/iu', '', $t) ?? $t;
    $t = preg_replace('/\s*[\x{1F4E6}\x{1F6D2}]+\s*$/u', '', $t) ?? $t;
    return trim($t);
}

function buscar_produto(string $url): array
{
    if (!url_publica($url)) return ['ok' => false, 'erro' => 'Link inválido.'];

    $r = http_get($url);
    if ($r['body'] === '') {
        return ['ok' => false, 'erro' => 'Não consegui abrir o link (' . ($r['error'] ?: 'HTTP ' . $r['code']) . ').'];
    }

    $html  = $r['body'];
    $final = $r['url'];
    $host  = strtolower((string) parse_url($final, PHP_URL_HOST));
    $plataforma = str_contains($host, 'shopee') ? 'Shopee'
        : (preg_match('/mercadoli[vb]re/', $host) ? 'Mercado Livre' : 'Outro');

    $metas = ler_metas($html);
    $ld    = ler_jsonld($html);
    $avisos = [];

    $titulo = $ld['name'] ?? $metas['og:title'] ?? $metas['twitter:title'] ?? '';
    if ($titulo === '' && preg_match('#<title[^>]*>(.*?)</title>#is', $html, $mt)) {
        $titulo = html_entity_decode(trim($mt[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
    $titulo = limpar_titulo((string) $titulo);

    $img = $metas['og:image'] ?? $metas['twitter:image'] ?? '';
    if ($img === '' && $ld && !empty($ld['image'])) {
        $i = $ld['image'];
        $img = is_array($i) ? (is_string(reset($i)) ? reset($i) : ($i['url'] ?? '')) : $i;
    }
    $img = $img !== '' ? absolutizar((string) $img, $final) : '';

    $preco = null;
    if ($ld && isset($ld['offers'])) {
        $of = $ld['offers'];
        if (isset($of[0])) $of = $of[0];
        $preco = num($of['price'] ?? $of['lowPrice'] ?? null);
    }
    $preco ??= num($metas['product:price:amount'] ?? $metas['og:price:amount'] ?? $metas['price'] ?? null);

    $antigo = null;
    if (preg_match('/Antes:\s*([\d.]+)\s*reais(?:\s*com\s*(\d+)\s*centavos)?/iu', $html, $ma)) {
        $antigo = (float) (str_replace('.', '', $ma[1]) . '.' . str_pad($ma[2] ?? '0', 2, '0', STR_PAD_LEFT));
    }
    if ($antigo !== null && $preco !== null && $antigo <= $preco) $antigo = null;

    if ($titulo === '') $avisos[] = 'Não consegui ler o nome do produto: preencha à mão.';
    if ($img === '')    $avisos[] = 'Sem imagem: cole a URL de uma imagem no campo "Imagem".';
    if ($preco === null) $avisos[] = 'Preço não encontrado: digite o valor.';
    if ($plataforma === 'Shopee' && ($titulo === '' || $preco === null)) {
        $avisos[] = 'A Shopee costuma bloquear leitura de página; se falhar sempre, a saída é a API de afiliados (hoje sem acesso) ou preencher à mão.';
    }

    return [
        'ok' => true, 'plataforma' => $plataforma, 'titulo' => $titulo, 'imagem' => $img,
        'preco' => $preco, 'preco_antigo' => $antigo, 'avisos' => $avisos, 'url_final' => $final,
    ];
}

/* ---------- Rotas ---------- */

if (($_GET['api'] ?? '') === 'fetch') {
    $url = trim((string) ($_GET['url'] ?? ''));
    json_out(buscar_produto($url));
}

if (isset($_GET['img'])) {
    $u = (string) $_GET['img'];
    if (!url_publica($u)) { http_response_code(400); exit('URL inválida'); }
    $r = http_get($u, ['Accept: image/avif,image/webp,image/*,*/*;q=0.8']);
    if ($r['body'] === '' || !str_starts_with($r['type'], 'image/')) { http_response_code(502); exit('Falha ao baixar imagem'); }
    $ext = match (true) {
        str_contains($r['type'], 'png')  => 'png',
        str_contains($r['type'], 'webp') => 'webp',
        default => 'jpg',
    };
    header('Content-Type: ' . $r['type']);
    if (isset($_GET['dl'])) header('Content-Disposition: attachment; filename="produto.' . $ext . '"');
    echo $r['body'];
    exit;
}
?><!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Gerador de anúncios</title>
<style>
  :root { --bg:#f3f1ec; --card:#fff; --ink:#1d2320; --mut:#66706a; --line:#dcd8cf; --zap:#128c4a; --bolha:#dcf6c8; }
  * { box-sizing: border-box; }
  body { margin:0; background:var(--bg); color:var(--ink); font:16px/1.45 system-ui, -apple-system, "Segoe UI", sans-serif; }
  main { max-width:1020px; margin:0 auto; padding:28px 18px 60px; display:grid; gap:24px; grid-template-columns: 1fr 1fr; align-items:start; }
  h1 { grid-column:1/-1; margin:0; font-size:1.5rem; }
  h1 small { display:block; font-weight:400; color:var(--mut); font-size:.95rem; margin-top:2px; }
  .painel { background:var(--card); border:1px solid var(--line); border-radius:10px; padding:18px; }
  label { display:block; font-size:.88rem; color:var(--mut); margin:12px 0 4px; }
  input[type=text], textarea { width:100%; padding:10px 11px; border:1px solid var(--line); border-radius:7px; font:inherit; background:#fff; }
  input:focus, textarea:focus, button:focus-visible { outline:2px solid var(--zap); outline-offset:1px; }
  .linha { display:flex; gap:10px; } .linha > * { flex:1; }
  button { font:inherit; cursor:pointer; border:1px solid var(--zap); background:var(--zap); color:#fff; padding:10px 14px; border-radius:7px; }
  button.sec { background:#fff; color:var(--zap); }
  .busca { display:flex; gap:10px; } .busca input { flex:1; } .busca button { flex:none; }
  #status { min-height:1.4em; margin:10px 0 0; font-size:.9rem; color:var(--mut); }
  #status.erro { color:#b3261e; } #status.aviso { color:#8a5a00; }
  .bolha { background:var(--bolha); border-radius:10px 10px 10px 2px; padding:6px; max-width:380px; box-shadow:0 1px 1px rgba(0,0,0,.12); }
  .bolha img { display:block; width:100%; border-radius:6px; background:#e9e6df; min-height:140px; object-fit:cover; }
  .bolha pre { margin:0; padding:8px 6px 6px; white-space:pre-wrap; word-break:break-word; font:inherit; font-size:.95rem; }
  .vazio { color:var(--mut); padding:30px 10px; text-align:center; }
  .acoes { display:flex; flex-wrap:wrap; gap:10px; margin-top:16px; }
  .acoes a.sec, .acoes button { text-decoration:none; }
  a.btn { display:inline-block; font:inherit; border:1px solid var(--zap); color:var(--zap); background:#fff; padding:10px 14px; border-radius:7px; }
  textarea { min-height:150px; resize:vertical; }
  @media (max-width:760px) { main { grid-template-columns:1fr; } }
</style>
</head>
<body>
<main>
  <h1>Gerador de anúncios <small>Cole o link de afiliado (Shopee ou Mercado Livre) e copie o anúncio pronto.</small></h1>

  <section class="painel">
    <div class="busca">
      <input type="text" id="url" placeholder="Cole o link de afiliado aqui" autofocus>
      <button id="buscar">Buscar</button>
    </div>
    <p id="status"></p>

    <label for="titulo">Nome do produto</label>
    <input type="text" id="titulo">
    <div class="linha">
      <div><label for="preco">Preço atual (R$)</label><input type="text" id="preco" inputmode="decimal"></div>
      <div><label for="antigo">Preço antigo (R$)</label><input type="text" id="antigo" inputmode="decimal"></div>
    </div>
    <label for="cupom">Cupom (opcional)</label>
    <input type="text" id="cupom">
    <label for="imagem">Imagem (URL)</label>
    <input type="text" id="imagem">
    <label for="texto">Texto do anúncio (editável)</label>
    <textarea id="texto"></textarea>
  </section>

  <section class="painel">
    <div id="previa"><p class="vazio">A prévia do anúncio aparece aqui.</p></div>
    <div class="acoes">
      <button id="copiarTexto">Copiar texto</button>
      <button id="copiarImg" class="sec">Copiar imagem</button>
      <a id="baixar" class="btn" href="#">Baixar imagem</a>
    </div>
  </section>
</main>

<script>
/* Edite aqui o modelo do anúncio. Linhas com campo vazio são removidas sozinhas. */
const LINHAS = [
  '🔥 {titulo}',
  '',
  '{preco}',
  '🎟️ Cupom: {cupom}',
  '',
  '👉 {link}',
];

const $ = s => document.querySelector(s);
const campos = ['titulo','preco','antigo','cupom','imagem'];
let linkAfiliado = '';
let textoEditadoNaMao = false;

const parsePreco = s => {
  s = String(s||'').replace(/[^\d,.]/g,'');
  if (!s) return null;
  if (s.includes(',')) s = s.replace(/\./g,'').replace(',','.');
  const n = parseFloat(s); return isNaN(n) ? null : n;
};
const brl = n => n.toLocaleString('pt-BR',{style:'currency',currency:'BRL'});
const paraCampo = n => n==null ? '' : n.toFixed(2).replace('.',',');

function montarPreco() {
  const p = parsePreco($('#preco').value), a = parsePreco($('#antigo').value);
  if (p==null) return '';
  if (a!=null && a>p) {
    const off = Math.round((1-p/a)*100);
    return `De ~${brl(a)}~ por *${brl(p)}* (${off}% OFF)`;
  }
  return `Por *${brl(p)}*`;
}

function montarTexto() {
  const v = { titulo:$('#titulo').value.trim(), preco:montarPreco(), cupom:$('#cupom').value.trim(), link:linkAfiliado };
  const out = [];
  for (const l of LINHAS) {
    const ks = [...l.matchAll(/\{(\w+)\}/g)].map(m=>m[1]);
    if (ks.some(k => !v[k])) continue;
    out.push(l.replace(/\{(\w+)\}/g,(_,k)=>v[k]));
  }
  return out.join('\n').replace(/\n{3,}/g,'\n\n').trim();
}

function atualizar(regerarTexto=true) {
  if (regerarTexto && !textoEditadoNaMao) $('#texto').value = montarTexto();
  const img = $('#imagem').value.trim();
  const src = img ? '?img='+encodeURIComponent(img) : '';
  $('#previa').innerHTML = (linkAfiliado || $('#titulo').value)
    ? `<div class="bolha">${src?`<img src="${src}" alt="">`:''}<pre></pre></div>` : '<p class="vazio">A prévia do anúncio aparece aqui.</p>';
  const pre = $('#previa pre'); if (pre) pre.textContent = $('#texto').value;
  $('#baixar').href = src ? src+'&dl=1' : '#';
}

function status(msg, tipo='') { const s=$('#status'); s.textContent=msg; s.className=tipo; }

async function buscar() {
  const url = $('#url').value.trim();
  if (!url) return;
  linkAfiliado = url; textoEditadoNaMao = false;
  status('Buscando…');
  try {
    const d = await (await fetch('?api=fetch&url='+encodeURIComponent(url))).json();
    if (!d.ok) { status(d.erro,'erro'); atualizar(); return; }
    $('#titulo').value = d.titulo||'';
    $('#preco').value  = paraCampo(d.preco);
    $('#antigo').value = paraCampo(d.preco_antigo);
    $('#imagem').value = d.imagem||'';
    status(d.avisos.length ? d.avisos.join(' ') : `${d.plataforma}: dados carregados. Confira antes de postar.`, d.avisos.length?'aviso':'');
  } catch (e) { status('Erro ao buscar. O servidor PHP está rodando?','erro'); }
  atualizar();
}

campos.forEach(c => $('#'+c).addEventListener('input', () => atualizar()));
$('#texto').addEventListener('input', () => { textoEditadoNaMao = true; atualizar(false); });
$('#buscar').addEventListener('click', buscar);
$('#url').addEventListener('keydown', e => { if (e.key==='Enter') buscar(); });

$('#copiarTexto').addEventListener('click', async () => {
  await navigator.clipboard.writeText($('#texto').value);
  status('Texto copiado.');
});

$('#copiarImg').addEventListener('click', async () => {
  const img = $('#imagem').value.trim();
  if (!img) return status('Sem imagem para copiar.','erro');
  try {
    const el = new Image();
    el.src = '?img='+encodeURIComponent(img);
    await el.decode();
    const c = document.createElement('canvas');
    c.width = el.naturalWidth; c.height = el.naturalHeight;
    c.getContext('2d').drawImage(el,0,0);
    const blob = await new Promise(r => c.toBlob(r,'image/png'));
    await navigator.clipboard.write([new ClipboardItem({'image/png':blob})]);
    status('Imagem copiada. Cole no WhatsApp com Ctrl+V.');
  } catch (e) { status('Não deu para copiar a imagem; use "Baixar imagem".','erro'); }
});

atualizar();
</script>
</body>
</html>