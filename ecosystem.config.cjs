module.exports = {
  apps: [{
    name: 'uiinspectore-api',
    script: '/var/www/uiinspectore-api/artisan',
    args: 'serve --host=127.0.0.1 --port=8008',
    cwd: '/var/www/uiinspectore-api',
    interpreter: 'php',
    instances: 1,
    autorestart: true,
    max_restarts: 10,
    max_memory_restart: '500M',
  }]
};
