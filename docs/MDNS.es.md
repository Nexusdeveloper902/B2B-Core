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

### Re-anuncio sin pregunta (TASK-047)

avahi solo **responde** consultas. En algunas redes Wi-Fi, la
multidifusión enviada *hacia* el host del backend nunca llega. El banco lo
midió: la consulta `_pulse._tcp` del ESP32 nunca alcanzó al host,
mientras que la multidifusión *desde* el host sí llegó al ESP32. Un
equipo cuya pregunta se pierde no recibe respuesta y usa su URL
compilada.

Por eso `./run serve` también ejecuta `scripts/mdns/announce.py`
(python3, solo stdlib), que funciona en la dirección que sí llega:

- **Qué envía:** cada segundo, una respuesta mDNS no solicitada (RFC 6762
  §8.3) con los mismos registros PTR, SRV, TXT y A, en cada interfaz
  IPv4, cada uno con la dirección propia de esa interfaz.
- **Puerto de origen:** la respuesta sale del puerto **5353**, porque los
  receptores (incluido ESP-IDF) ignoran respuestas de cualquier otro
  puerto. Se usa un socket de vida corta, así que el tráfico de avahi no
  se ve afectado.
- **TTL:** los registros duran 120 s, así una dirección vieja caduca
  pronto.
- **Cambios de red:** sigue los cambios de interfaz, como unirse a un
  hotspot o renovar DHCP.

Un equipo que busca durante su ventana de consulta la recibe, haya
llegado o no su pregunta. Resultado de banco en la LAN con pérdida de
multidifusión:

| Anunciador | Arranques del ESP32 | Descubierto |
|---|---|---|
| activo | 4 | 4 |
| apagado | 2 | 0 (reserva compilada) |

Sin python3, `serve` solo avisa, y avahi sigue respondiendo.

Uso manual: `python3 scripts/mdns/announce.py --port 8000`.

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

## Puente de sonido Android (TASK-047)

La app de teléfono `pulse-credential` es un segundo cliente de este
contrato. Busca `_pulse._tcp` con NSD de Android y marca la IP y el
puerto resueltos. Si mDNS no responde, por ejemplo porque el multicast
falla en el propio hotspot del teléfono, recorre sus subredes privadas
(tamaño /24) en `:8000` y confirma que es Pulse vía
`/manifest.webmanifest`. Luego inicia sesión con una cuenta de personal
(el login de cocina sembrado) y llama a `GET /realtime/token`, cuya `url`
ya trae el host descubierto. Después abre el feed en tiempo real.
**No** hace falta anunciar `8081`.

El evento de cada trama `tap` en vivo lleva `feedback`: `accepted`
(servido) o `rejected` (marcado, `served=false`). El teléfono lo mapea a
un pitido local y lo reproduce por su salida multimedia, que es el
parlante Bluetooth cuando hay uno conectado. El campo se deriva de
`served`, así que la cocina y el teléfono siempre coinciden.

Algunos toques se responden pero **no** escriben fila de evento: un
segundo toque de aula el mismo día (cuenta el primero) y una tarjeta
desconocida o inactiva. Esos toques quedan en la tabla de solo anexado
`tap_feedback`, y `realtime:serve` envía cada uno a las conexiones de
administración y cocina como

`{"type":"feedback","feedback":{"cue":"accepted|rejected","reason":"duplicate|not_found|inactive",...}}`

Así, cada toque produce exactamente una señal. La escritura es de mejor
esfuerzo, así que nunca puede hacer fallar el toque del dispositivo.

Topología de demo: el teléfono crea el hotspot, y el portátil del
backend se conecta a él y ejecuta `./run serve --host=0.0.0.0`.
