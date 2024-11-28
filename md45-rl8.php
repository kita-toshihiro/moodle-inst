<?php
// サーバによって違う parameters (各自編集してください):
$host = "xxx.xxx.xxx.xxx";  // set host IP address
// $host = "your.host.jp";  // or DNS hostname
$ver = "405"; // Moodle 4.5
$mdpass= 'your-pass%%word'; // moodle admin, mysql user ('&'はダメみたい)
$adminemail = "youradminmail@foo.bar";

$md = 'moodle'; // 同一サーバ上で2つ目のMoodleをセットアップする時は変える

// Moodle 動作に必要な packages をインストール
system("dnf -y install php-opcache php-gd php-curl php-mysqlnd php-soap php-xml php-mbstring php-intl php-pecl-zip php-sodium php-ldap php-pspell ");
system("dnf -y install mod_ssl git");
system("dnf -y install graphviz aspell ghostscript clamav ");

// Moodle4.5 は MariaDB 10.6 以上を要求
system("dnf -y module list mariadb");
system("dnf -y module reset mariadb");
system("dnf -y module enable mariadb:10.11");
system("dnf -y install mariadb");

// PHP設定 max_input_vars を 5000 に
system("cp /etc/php.ini /etc/php.ini.orig");
system("sed -i '/max_input_vars = .*/a\max_input_vars = 5000' /etc/php.ini");

system("firewall-cmd --add-service=http --add-service=https");
system("firewall-cmd --runtime-to-permanent");

system("systemctl enable httpd");
system("systemctl start httpd");

system("systemctl enable mariadb");
system("systemctl start mariadb");

// Download Moodle
$mdroot = "/var/www/html/${md}";
if ($md == "moodle"){
  system("cd /var/www/html/ ; git clone git://git.moodle.org/moodle.git");
}else{
  system("cd /tmp/ ; git clone git://git.moodle.org/moodle.git");
  system("mv /tmp/moodle ${mdroot}");
}
system("cd ${mdroot}/; git checkout -b local_${ver}_STABLE origin/MOODLE_${ver}_STABLE");
system("chmod 755 ${mdroot}");

// data dir
$dataroot = "/var/www/${md}d";
system("mkdir ${dataroot}; chown apache:apache ${dataroot}/");

// DB
$dbuser = "dbu${md}";
system("mysql -u root -e \"CREATE DATABASE $md  DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; \";");
system("mysql -u root -e \"CREATE USER ${dbuser}@localhost IDENTIFIED BY '$mdpass'; \"; ");
system("mysql -u root -e \"GRANT ALL ON ${md}.* TO ${dbuser}@localhost ; \"; ");
// mysql パスワードありの場合は、 mysql -u root -pmysqlpassword -e ... のように書く

// moodle directory を書き込み可能に
system("chown -R apache:apache ${mdroot}");

$url = "http://${host}/${md}";  // if apache documentroot is as default

// non-interactive install command
system("cd ${mdroot}/;  sudo -u apache /usr/bin/php  admin/cli/install.php --non-interactive --agree-license --lang=ja --wwwroot=\"${url}\" --dataroot=\"${dataroot}\" --dbtype=mariadb --dbname=$md --dbuser=${dbuser} --dbpass=$mdpass --fullname=\"${md} site\" --shortname=${md} --adminpass=$mdpass --adminemail=$adminemail");

// moodle directory を書き込みできないように
system("chown -R root:root ${mdroot}");
system("chmod 644 ${mdroot}/config.php");

echo("$url でMoodleが使えます。\n adminパスワードは $mdpass です。\n 以下を crontab に追加してください: \n* * * * * php ${mdroot}/admin/cli/cron.php > /dev/null 2>&1 \n");

// おまけ： Let's encrypt の設定
// system("yum -y install certbot");
// system("certbot certonly -n --webroot -w /var/www/html -d $host -m $adminemail --agree-tos ");
// system("certbot install --apache --no-redirect");  // これがもしダメなら手動で ssl.conf を変更。

