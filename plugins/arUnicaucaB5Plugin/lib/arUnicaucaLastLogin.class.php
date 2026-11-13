<?php

/*
 * «Último acceso» de cada usuario (columna de Administrador → Usuarios).
 *
 * AtoM no guarda la fecha del último inicio de sesión, así que se registra en la tabla
 * genérica de propiedades (property / property_i18n) con el nombre ahLastLogin, sin
 * cambiar el esquema de la base de datos. Se escribe al iniciar sesión (loginAction).
 * Las cuentas que no han iniciado sesión desde este cambio muestran «—».
 */
class arUnicaucaLastLogin
{
    public const PROPERTY = 'ahLastLogin';
    public const TIMEZONE = 'America/Bogota';

    public static function record($userId): void
    {
        if (empty($userId)) {
            return;
        }

        // Un fallo al guardar la fecha nunca debe impedir el inicio de sesión.
        try {
            $now = new DateTime('now', new DateTimeZone(self::TIMEZONE));

            if (null === $property = QubitProperty::getOneByObjectIdAndName($userId, self::PROPERTY)) {
                $property = new QubitProperty();
                $property->objectId = $userId;
                $property->name = self::PROPERTY;
            }

            $property->setValue($now->format('Y-m-d H:i'), ['sourceCulture' => true]);
            $property->save();
        } catch (Exception $e) {
            sfContext::getInstance()->getLogger()->err('arUnicaucaLastLogin: '.$e->getMessage());
        }
    }

    // Devuelve la fecha como «dd/mm/aaaa hh:mm», o null si el usuario no tiene registro.
    public static function format($userId): ?string
    {
        if (null === $property = QubitProperty::getOneByObjectIdAndName($userId, self::PROPERTY)) {
            return null;
        }

        $value = $property->getValue(['sourceCulture' => true]);
        $date = DateTime::createFromFormat('Y-m-d H:i', (string) $value, new DateTimeZone(self::TIMEZONE));

        return $date ? $date->format('d/m/Y H:i') : null;
    }
}
