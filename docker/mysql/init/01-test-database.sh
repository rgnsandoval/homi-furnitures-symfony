#!/usr/bin/env bash
# Creates the test database on first start of an empty data volume.
set -e
mysql -uroot -p"$MYSQL_ROOT_PASSWORD" <<'EOF'
CREATE DATABASE IF NOT EXISTS homi_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
GRANT ALL PRIVILEGES ON homi_test.* TO 'homi'@'%';
FLUSH PRIVILEGES;
EOF
