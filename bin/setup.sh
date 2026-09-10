#!/usr/bin/env bash
set -euo pipefail

if [ -f .env ]; then
  set -a
  # shellcheck disable=SC1091
  . ./.env
  set +a
fi

docker compose up -d database wordpress mailpit adminer
echo "Waiting for WordPress files and database..."
until docker compose run --rm wpcli core version >/dev/null 2>&1; do sleep 3; done

if ! docker compose run --rm wpcli core is-installed >/dev/null 2>&1; then
  docker compose run --rm wpcli core install \
    --url="http://localhost:${WORDPRESS_PORT:-8080}" \
    --title="${WORDPRESS_SITE_TITLE:-OneClickTranslation Dev}" \
    --admin_user="${WORDPRESS_ADMIN_USER:-admin}" \
    --admin_password="${WORDPRESS_ADMIN_PASSWORD:-admin}" \
    --admin_email="${WORDPRESS_ADMIN_EMAIL:-admin@example.test}" \
    --skip-email
fi

docker compose run --rm wpcli rewrite structure '/%postname%/' --hard
docker compose run --rm wpcli plugin activate oneclicktranslation

if [ "${INSTALL_POLYLANG:-true}" = "true" ]; then
  docker compose run --rm wpcli plugin install polylang --activate
  docker compose run --rm wpcli eval 'if (function_exists("PLL")) { foreach (["pl_PL"=>"pl","en_US"=>"en","de_DE"=>"de","fr_FR"=>"fr"] as $locale=>$slug) { if (!PLL()->model->get_language($slug)) { $name=["pl"=>"Polski","en"=>"English","de"=>"Deutsch","fr"=>"Français"][$slug]; PLL()->model->add_language(["name"=>$name,"slug"=>$slug,"locale"=>$locale,"rtl"=>0,"term_group"=>0]); } } update_option("polylang", array_merge((array)get_option("polylang"), ["default_lang"=>"pl"])); }'
fi

docker compose run --rm wpcli eval '
$pages=["Home"=>"Witaj w OneClickTranslation","About"=>"Poznaj naszą firmę i zespół.","Services"=>"Oferujemy projektowanie, wdrożenia i wsparcie.","Contact"=>"Skontaktuj się z nami przez formularz."];
foreach($pages as $title=>$content){$existing=get_page_by_title($title,OBJECT,"page");$id=$existing?$existing->ID:wp_insert_post(["post_type"=>"page","post_status"=>"publish","post_title"=>$title,"post_content"=>"<!-- wp:heading --><h2>".esc_html($content)."</h2><!-- /wp:heading --><!-- wp:paragraph --><p>To jest przykładowa treść z linkiem <a href=\"/contact/\">kontakt</a> i shortcode [button url=\"/kontakt\" class=\"primary\"]Napisz do nas[/button].</p><!-- /wp:paragraph -->"]);if(function_exists("pll_set_post_language"))pll_set_post_language($id,"pl");update_post_meta($id,"hero_title","Najlepsze rozwiązania dla Twojej firmy");update_post_meta($id,"hero_description","Tworzymy użyteczne produkty cyfrowe.");update_post_meta($id,"price",199);update_post_meta($id,"sections",[["heading"=>"Pierwsza sekcja","text"=>"Opis pierwszej sekcji","button"=>["title"=>"Dowiedz się więcej","url"=>"/kontakt"]]]);}
$post=get_page_by_title("Blog post",OBJECT,"post");if(!$post){$id=wp_insert_post(["post_type"=>"post","post_status"=>"publish","post_title"=>"Blog post","post_content"=>"Treść przykładowego wpisu blogowego.","post_excerpt"=>"Krótki opis wpisu."]);if(function_exists("pll_set_post_language"))pll_set_post_language($id,"pl");}
if(post_type_exists("portfolio")){for($i=1;$i<=2;$i++){$title="Projekt portfolio ".$i;if(!get_page_by_title($title,OBJECT,"portfolio")){$id=wp_insert_post(["post_type"=>"portfolio","post_status"=>"publish","post_title"=>$title,"post_content"=>"Opis realizacji dla klienta."]);if(function_exists("pll_set_post_language"))pll_set_post_language($id,"pl");update_post_meta($id,"client_name","Klient ".$i);update_post_meta($id,"project_url","https://example.test/project-".$i);}}}
'

echo "WordPress: http://localhost:${WORDPRESS_PORT:-8080}"
echo "Mailpit:   http://localhost:${MAILPIT_PORT:-8025}"
echo "Adminer:   http://localhost:${ADMINER_PORT:-8081}"
echo "Login:     ${WORDPRESS_ADMIN_USER:-admin} / ${WORDPRESS_ADMIN_PASSWORD:-admin}"
