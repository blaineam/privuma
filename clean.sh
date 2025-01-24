#!/bin/bash
docker compose exec -it php-cron php sync.php debug=1 clean=1