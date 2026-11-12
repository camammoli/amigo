# Amigo Invisible

[![Archivado en Software Heritage](https://archive.softwareheritage.org/badge/origin/https://github.com/camammoli/amigo/)](https://archive.softwareheritage.org/browse/origin/?origin_url=https://github.com/camammoli/amigo)

**[→ Probalo en mammoli.ar/amigo](https://mammoli.ar/amigo/)**

Organizá el sorteo de amigo invisible en segundos, sin registro y con un tap por persona para mandar el resultado por WhatsApp.

## Cómo funciona

1. Ingresás el nombre del grupo, los participantes (mínimo 3) y el presupuesto sugerido
2. El sistema sortea al azar (derangement — nadie se saca a sí mismo, sin pares mutuos cuando es posible)
3. Ves todos los pares asignados
4. Por cada persona, tocás "Enviar por WhatsApp" → se abre WhatsApp con el mensaje listo → un tap para mandar

Cada participante recibe algo así:

```
🎁 Amigo Invisible — Familia 2025

Hola Carlos! Tu amigo invisible es: *María* 🎁
Presupuesto sugerido: $5.000

¡No se lo cuentes a nadie! 🤫
— Ariel
```

## Stack

- PHP 7.4+ · SQLite (PDO) · Vanilla JS
- Sin frameworks, sin dependencias externas
- PWA-ready (funciona desde pantalla de inicio)

## Auto-hospedaje

```bash
git clone https://github.com/camammoli/amigo.git
cp -r amigo/web/ /var/www/html/amigo/
# La BD se crea automáticamente en web/data/amigo.db
```

Requiere PHP con `pdo_sqlite`. HTTPS recomendado.

## Licencia

MIT · [Invitame un café](https://cafecito.app/mammoli) ☕
