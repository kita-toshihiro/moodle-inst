<?php
// サーバによって違う parameters (各自編集してください):
$host = "xxx.xxx.xxx.xxx";  // set host IP address or DNS hostname
$ver = "503"; // Moodle 5.3 (MOODLE_503_STABLE)
$mdpass = 'Your%Pass-Word'; // moodle admin, mysql user ('&' は避ける)
$adminemail = "youradminmail@foo.bar";

$md = 'moodle'; // 同一サーバ上で2つ目のMoodleをセットアップする時は変える

// パッケージリストの更新
system("apt update");

// Nginx, MySQL, PHP 8.3 (FPM版) および必要な拡張モジュールをインストール
system("apt -y install nginx mysql-client mysql-server php8.3-fpm");
system("apt -y install git graphviz aspell ghostscript clamav php8.3-pspell php8.3-curl php8.3-gd php8.3-intl php8.3-mysql php8.3-xml php8.3-ldap php8.3-zip php8.3-soap php8.3-mbstring php8.3-opcache");

// PHP設定 (FPM および CLI)
// max_input_vars を 5000 に設定
system("cp /etc/php/8.3/fpm/php.ini /etc/php/8.3/fpm/php.ini.orig");
system("sed -i 's/;max_input_vars = .*/max_input_vars = 5000/' /etc/php/8.3/fpm/php.ini");
system("cp /etc/php/8.3/cli/php.ini /etc/php/8.3/cli/php.ini.orig");
system("sed -i 's/;max_input_vars = .*/max_input_vars = 5000/' /etc/php/8.3/cli/php.ini");

// Moodle推奨のOPcache設定
system("sed -i 's/;opcache.enable = .*/opcache.enable = 1/' /etc/php/8.3/fpm/php.ini");
system("sed -i 's/;opcache.memory_consumption = .*/opcache.memory_consumption = 128/' /etc/php/8.3/fpm/php.ini");
system("sed -i 's/;opcache.max_accelerated_files = .*/opcache.max_accelerated_files = 10000/' /etc/php/8.3/fpm/php.ini");
system("sed -i 's/;opcache.revalidate_freq = .*/opcache.revalidate_freq = 60/' /etc/php/8.3/fpm/php.ini");

system("systemctl restart php8.3-fpm.service");

$mdroot = "/var/www/html/${md}";

// GitHubからMoodleをクローン
$git_repo = "https://github.com/moodle/moodle.git";
if ($md == "moodle") {
    system("mkdir -p /var/www/html");
    system("cd /var/www/html/ && git clone ${git_repo}");
} else {
    system("cd /tmp/ && git clone ${git_repo}");
    system("mv /tmp/moodle ${mdroot}");
}
// 指定バージョンのブランチへチェックアウト
system("cd ${mdroot}/ && git checkout -b local_${ver}_STABLE origin/MOODLE_${ver}_STABLE");

/*
// またはMoodleアーカイブをダウンロードして展開（こちらのほうが速いかも）
$archive_url = "https://download.moodle.org/download.php/direct/stable${ver}/moodle-latest-${ver}.tgz";
echo "Moodleアーカイブをダウンロード中...\n";
system("mkdir -p /var/www/html");
system("cd /tmp && wget -O moodle.tgz \"${archive_url}\"");
system("tar -zxf /tmp/moodle.tgz -C /var/www/html/");
system("rm -f /tmp/moodle.tgz");
*/

system("chmod 755 ${mdroot}");

// Moodleデータディレクトリの作成
$dataroot = "/var/www/${md}d";
system("mkdir -p ${dataroot} && chown -R www-data:www-data ${dataroot}/ && chmod 770 ${dataroot}");

// データベースのセットアップ
$dbuser = "dbu${md}";
system("mysql -u root -e \"CREATE DATABASE IF NOT EXISTS ${md} DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;\"");
system("mysql -u root -e \"CREATE USER IF NOT EXISTS '${dbuser}'@'localhost' IDENTIFIED BY '${mdpass}';\"");
system("mysql -u root -e \"GRANT ALL ON ${md}.* TO '${dbuser}'@'localhost'; FLUSH PRIVILEGES;\"");

// Nginx VirtualHost の作成 (/moodle パスへのアクセスおよび PHP-FPM 連携)
$nginx_conf = <<<EOL
server {
    listen 80 default_server;
    listen [::]:80 default_server;
    server_name ${host};
    root /var/www/html;
    index index.php index.html index.htm;

    client_max_body_size 100M;

    location / {
        try_files \$uri \$uri/ =404;
    }

    # Moodle 用の location 設定 (slash arguments 対応)
    location ~ [^/]\.php(/|$) {
        fastcgi_split_path_info ^(.+\.php)(/.+)$;
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
        fastcgi_index index.php;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME \$document_root\$fastcgi_script_name;
        fastcgi_param PATH_INFO \$fastcgi_path_info;
    }
}
EOL;

file_put_contents("/etc/nginx/sites-available/default", $nginx_conf);
system("nginx -t && systemctl restart nginx.service");

// インストール実行のため一時的に所有権を www-data に変更
system("chown -R www-data:www-data ${mdroot}");

$url = "http://${host}/${md}";

// CLI インストールコマンドの実行
system("cd ${mdroot}/ && sudo -u www-data /usr/bin/php admin/cli/install.php --non-interactive --agree-license --lang=ja --wwwroot=\"${url}\" --dataroot=\"${dataroot}\" --dbtype=mysqli --dbname=${md} --dbuser=${dbuser} --dbpass=\"${mdpass}\" --fullname=\"${md} site\" --shortname=${md} --adminpass=\"${mdpass}\" --adminemail=\"${adminemail}\"");

// セキュリティのため Moodle ディレクトリの所有権を root に戻す
system("chown -R root:root ${mdroot}");
system("chmod 644 ${mdroot}/config.php");

// ファイアウォールの設定 (UFW)
system("ufw allow 'Nginx Full'");

echo "\n=======================================================\n";
echo "セットアップが完了しました。\n";
echo "URL: {$url}\n";
echo "Adminパスワード: {$mdpass}\n\n";
echo "定期実行タスク (Cron) を登録するため、以下を crontab に追加してください:\n";
echo "* * * * * sudo -u www-data /usr/bin/php {$mdroot}/admin/cli/cron.php > /dev/null 2>&1\n";
echo "=======================================================\n";

// おまけ： Let's Encrypt (Nginx 用)
// system("apt -y install certbot python3-certbot-nginx");
// system("certbot --nginx -d {$host} -m {$adminemail} --agree-tos -n");

