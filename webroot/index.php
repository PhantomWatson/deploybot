<?php
    $appDir = dirname(dirname(__FILE__));
    spl_autoload_register(function ($className) use ($appDir) {
        $className = str_replace('App\\', '', $className);
        include  $appDir . '/src/' . $className . '.php';
    });

    function verifySignature($body) {
        $headers = getallheaders();
        $secret = include dirname(dirname(__FILE__)) . '/config/github_secret.php';
        return hash_equals(
            'sha256=' . hash_hmac('sha256', $body, $secret),
            $headers['X-Hub-Signature-256'] ?? ''
        );
    }

    if (empty($_POST)) {
        exit;
    }

    // Verify
    $body = file_get_contents("php://input");
    if (verifySignature($body) === false) {
        http_response_code(403);
        echo "unauthorized";
        exit;
    }

    // Deploy
    $deploy = new App\Deploy();
    echo $deploy->triggerMsg . "\n" . $deploy->screenOutput->content;
