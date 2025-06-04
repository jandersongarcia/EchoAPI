<?php

namespace App\Controllers;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Medoo\Medoo;
use Monolog\Logger;

class AuthController
{
    private $db;
    private $logger;

    public function __construct(Medoo $db, Logger $logger)
    {
        $this->db = $db;
        $this->logger = $logger;
    }

    public function register(): Response
    {
        $request = Request::createFromGlobals();
        $data = json_decode($request->getContent(), true);

        $name = $data['name'] ?? null;
        $surname = $data['surname'] ?? null;
        $email = $data['email'] ?? null;
        $password = $data['password'] ?? null;
        $language = $data['language'] ?? 'us';
        $plan = 1;

        if (!$name || !$email || !$password) {
            return $this->response('fields_not_filled',400);
        }

        $exists = $this->db->get("users", "*", ["email" => $email]);
        if ($exists) {
            return $this->response('email_already_registered',409);
        }

        $hashedPassword = password_hash($password, PASSWORD_DEFAULT);

        try {
            $this->db->insert("users", [
                "name" => $name,
                "email" => $email,
                "password" => $hashedPassword,
                "surname" => $surname,
                "language" => $language,
                "plan_id" => $plan,
            ]);
        } catch (\PDOException $e) {
            return $this->response('database_error',500);
        }

        return $this->response('registered_successfully',201);
    }

    private function response(string $message, int $status = 200): Response
    {
        return new Response(json_encode(['message' => $message, 'code' => $status]), $status, [
            'Content-Type' => 'application/json'
        ]);
    }
}
