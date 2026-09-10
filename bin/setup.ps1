$ErrorActionPreference = 'Stop'

if (Test-Path '.env') {
    Get-Content '.env' | ForEach-Object {
        $line = $_.Trim()
        if ($line -and -not $line.StartsWith('#') -and $line.Contains('=')) {
            $name, $value = $line.Split('=', 2)
            [Environment]::SetEnvironmentVariable($name.Trim(), $value.Trim().Trim('"').Trim("'"), 'Process')
        }
    }
}

docker compose up -d database wordpress mailpit adminer
Write-Host 'Waiting for WordPress files and database...'
do {
    Start-Sleep -Seconds 3
    docker compose run --rm wpcli core version *> $null
} while ($LASTEXITCODE -ne 0)

docker compose run --rm wpcli core is-installed *> $null
if ($LASTEXITCODE -ne 0) {
    $port = if ($env:WORDPRESS_PORT) { $env:WORDPRESS_PORT } else { '8080' }
    $siteTitle = if ($env:WORDPRESS_SITE_TITLE) { $env:WORDPRESS_SITE_TITLE } else { 'OneClickTranslation Dev' }
    $adminUser = if ($env:WORDPRESS_ADMIN_USER) { $env:WORDPRESS_ADMIN_USER } else { 'admin' }
    $adminPassword = if ($env:WORDPRESS_ADMIN_PASSWORD) { $env:WORDPRESS_ADMIN_PASSWORD } else { 'admin' }
    $adminEmail = if ($env:WORDPRESS_ADMIN_EMAIL) { $env:WORDPRESS_ADMIN_EMAIL } else { 'admin@example.test' }
    docker compose run --rm wpcli core install --url="http://localhost:$port" --title="$siteTitle" --admin_user="$adminUser" --admin_password="$adminPassword" --admin_email="$adminEmail" --skip-email
}

docker compose run --rm wpcli rewrite structure '/%postname%/' --hard
docker compose run --rm wpcli plugin activate oneclicktranslation
$installPolylang = if ($env:INSTALL_POLYLANG) { $env:INSTALL_POLYLANG } else { 'true' }
if ($installPolylang -eq 'true') {
    docker compose run --rm wpcli plugin install polylang --activate
    docker compose run --rm wpcli eval 'if (function_exists("PLL")) { foreach (["pl_PL"=>"pl","en_US"=>"en","de_DE"=>"de","fr_FR"=>"fr"] as $locale=>$slug) { if (!PLL()->model->get_language($slug)) { $name=["pl"=>"Polski","en"=>"English","de"=>"Deutsch","fr"=>"Français"][$slug]; PLL()->model->add_language(["name"=>$name,"slug"=>$slug,"locale"=>$locale,"rtl"=>0,"term_group"=>0]); } } update_option("polylang", array_merge((array)get_option("polylang"), ["default_lang"=>"pl"])); }'
}

docker compose run --rm wpcli eval '$pages=["Home"=>"Witaj w OneClickTranslation","About"=>"Poznaj naszą firmę i zespół.","Services"=>"Oferujemy projektowanie, wdrożenia i wsparcie.","Contact"=>"Skontaktuj się z nami przez formularz."];foreach($pages as $title=>$content){$existing=get_page_by_title($title,OBJECT,"page");$id=$existing?$existing->ID:wp_insert_post(["post_type"=>"page","post_status"=>"publish","post_title"=>$title,"post_content"=>"<!-- wp:heading --><h2>".esc_html($content)."</h2><!-- /wp:heading --><!-- wp:paragraph --><p>Przykładowa treść i [button url=\"/kontakt\" class=\"primary\"]Napisz do nas[/button].</p><!-- /wp:paragraph -->"]);if(function_exists("pll_set_post_language"))pll_set_post_language($id,"pl");update_post_meta($id,"hero_title","Najlepsze rozwiązania dla Twojej firmy");update_post_meta($id,"hero_description","Tworzymy użyteczne produkty cyfrowe.");update_post_meta($id,"price",199);update_post_meta($id,"sections",[["heading"=>"Pierwsza sekcja","text"=>"Opis pierwszej sekcji","button"=>["title"=>"Dowiedz się więcej","url"=>"/kontakt"]]]);}if(!get_page_by_title("Blog post",OBJECT,"post")){$id=wp_insert_post(["post_type"=>"post","post_status"=>"publish","post_title"=>"Blog post","post_content"=>"Treść przykładowego wpisu blogowego."]);if(function_exists("pll_set_post_language"))pll_set_post_language($id,"pl");}if(post_type_exists("portfolio")){for($i=1;$i<=2;$i++){$title="Projekt portfolio ".$i;if(!get_page_by_title($title,OBJECT,"portfolio")){$id=wp_insert_post(["post_type"=>"portfolio","post_status"=>"publish","post_title"=>$title,"post_content"=>"Opis realizacji dla klienta."]);if(function_exists("pll_set_post_language"))pll_set_post_language($id,"pl");update_post_meta($id,"client_name","Klient ".$i);update_post_meta($id,"project_url","https://example.test/project-".$i);}}}'

$port = if ($env:WORDPRESS_PORT) { $env:WORDPRESS_PORT } else { '8080' }
$adminUser = if ($env:WORDPRESS_ADMIN_USER) { $env:WORDPRESS_ADMIN_USER } else { 'admin' }
$adminPassword = if ($env:WORDPRESS_ADMIN_PASSWORD) { $env:WORDPRESS_ADMIN_PASSWORD } else { 'admin' }
Write-Host "WordPress: http://localhost:$port"
Write-Host 'Mailpit:   http://localhost:8025'
Write-Host 'Adminer:   http://localhost:8081'
Write-Host "Login:     $adminUser / $adminPassword"
