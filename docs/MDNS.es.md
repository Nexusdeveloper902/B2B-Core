# Descubrimiento por mDNS — `_pulse._tcp.local`

> Also available in: [English](MDNS.md)

Los dispositivos ESP32 encuentran el backend Pulse con DNS-SD en vez de
una IP de LAN fija. El backend se anuncia a sí mismo; el firmware
(`lib/PulseDiscovery` + `PresenceCore/PulseEndpoint.h` de `B2B-Firmware`)
consulta al arrancar y reconsulta ante cualquier fallo de transporte —
un backend con IP rotada por DHCP se retoma sin reiniciar, sin
reflashear y sin editar configuración.

## Contrato

| Elemento | Valor |
|---|---|
| Servicio | `_pulse._tcp.local` |
| Puerto | el puerto web/API (puerto de `./run serve`, `8000` por defecto) |
| TXT `version` | `1` (formato del anuncio) |
| TXT `protocol` | `1` (el firmware habla `1`; un resultado con otro valor se rechaza, sin TXT se acepta) |
| TXT `api` | `/api` (ruta base, informativa) |

El **nombre** del servicio ES la identidad del backend: cualquier
respuesta a esa consulta se trata como un backend Pulse. El puerto del
canal en vivo (`8081`) deliberadamente NO se anuncia — solo los
navegadores de los paneles usan ese WebSocket; el firmware es HTTP plano
y nunca lo abre.

## Cómo se publica

`./run serve` publica el anuncio con `avahi-publish-service` (servicio
del SO, no código Laravel — Laravel queda intacto):

```bash
./run serve --host=0.0.0.0   # demo en LAN: web :8000 + canal :8081 + _pulse._tcp
B2B_MDNS=0 ./run serve       # arrancar SIN el anuncio
```

El publicador sigue a `--port=`/`B2B_SERVE_PORT` solo y se retira con
Ctrl+C junto a los servidores. Sin CLI o sin `avahi-daemon` solo avisa —
el web arranca igual y los equipos conservan su reserva `API_BASE_URL`
compilada. Escuchar en loopback (el `127.0.0.1` por defecto) también
avisa: los equipos LAN no llegan a un servidor loopback, así que las
demos con hardware necesitan `--host=0.0.0.0`.

Alternativa estática (máquina fija de banco, solo puerto fijo):
`scripts/mdns/pulse.service` → copiar a `/etc/avahi/services/` y fijar
`<port>` al puerto de serve. Prefiere `./run serve` — el archivo
estático no sigue un puerto personalizado.

## Nombre de host

Avahi anuncia el `<hostname>.local` propio de la máquina en el registro
SRV. Para un `pulse.local` literal, renombra la máquina de banco
(`sudo hostnamectl set-hostname pulse`). El firmware no lo necesita:
marca la IP + puerto resueltos, nunca el nombre.

## Verificar

```bash
avahi-browse -rt _pulse._tcp
# = eth0 IPv4 Pulse  _pulse._tcp local
#   hostname = [banco.local], port = [8000], txt = ["version=1" "protocol=1" "api=/api"]
```

Prueba de rotación de IP: anota la línea `Backend:` del equipo, cambia
la IP LAN del servidor (o reinicia `serve` en otra interfaz), toca una
vez (espera un único `NetworkError` + línea `[DISC] backend
redescubierto`), toca de nuevo — el evento se registra contra la nueva
dirección.
