<?php

/*
 * Política de dominio del Archivo Histórico (Universidad del Cauca):
 * solo correos @unicauca.edu.co pueden iniciar sesión o ser creados/editados.
 */
class arUnicaucaEmailDomainPolicy
{
    public const PATTERN = '/^[^@\s]+@unicauca\.edu\.co$/i';

    public static function message(): string
    {
        return 'Debe ingresar un correo válido de la Unicauca, @unicauca.edu.co.';
    }

    // Reemplaza a sfValidatorEmail: mismo comportamiento, pero el patrón exige el dominio.
    public static function validator(array $messages = []): sfValidatorEmail
    {
        return new sfValidatorEmail(
            ['required' => true, 'trim' => true, 'pattern' => self::PATTERN],
            array_merge(['invalid' => self::message()], $messages)
        );
    }
}