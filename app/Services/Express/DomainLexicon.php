<?php

namespace App\Services\Express;

use Illuminate\Support\Collection;

/**
 * A small ES↔EN domain lexicon for the deterministic fit: catalogs name their
 * tools and enum values in English while tenants ask in Spanish, so "motivos
 * semanales" matched nothing against get-tickets-by-dimension unless the
 * description happened to be translated. Expanding the topic words with their
 * translations (both directions) raises the economy hit-rate and the enum-cut
 * recall honestly — every added word still has to MATCH something real; the
 * lexicon invents no tools.
 */
class DomainLexicon
{
    /**
     * ES → EN. Inverted automatically for the other direction. Stems are
     * enough — the matchers use containment and shared prefixes.
     *
     * @var array<string, list<string>>
     */
    private const ES_EN = [
        'motivo' => ['reason'], 'motivos' => ['reasons', 'reason'],
        'causa' => ['cause'], 'causas' => ['causes', 'cause'],
        'semanal' => ['weekly'], 'semanales' => ['weekly'], 'semana' => ['week'],
        'diario' => ['daily'], 'diaria' => ['daily'],
        'mensual' => ['monthly'], 'mes' => ['month'],
        'tendencia' => ['trend', 'series'], 'evolucion' => ['trend', 'series', 'time'],
        'quejas' => ['complaints'], 'queja' => ['complaint'],
        'ventas' => ['sales'], 'venta' => ['sale'],
        'pedidos' => ['orders'], 'pedido' => ['order'], 'ordenes' => ['orders'],
        'clientes' => ['customers', 'clients'], 'cliente' => ['customer', 'client'],
        'usuarios' => ['users'], 'usuario' => ['user'],
        'canal' => ['channel'], 'canales' => ['channels'],
        'prioridad' => ['priority'], 'estado' => ['status', 'state'],
        'categoria' => ['category'], 'categorias' => ['categories'],
        'resolucion' => ['resolution'], 'tiempo' => ['time'],
        'volumen' => ['volume'], 'ingresos' => ['revenue', 'income'],
        'facturacion' => ['billing', 'invoic'], 'pagos' => ['payments'], 'pago' => ['payment'],
        'devoluciones' => ['returns', 'refunds'], 'reembolsos' => ['refunds'], 'reembolso' => ['refund'],
        'inventario' => ['inventory', 'stock'],
        'agentes' => ['agents'], 'agente' => ['agent'],
        'convenio' => ['agreement'], 'convenios' => ['agreements'],
        'encuestas' => ['surveys'], 'encuesta' => ['survey'],
        'comentarios' => ['comments'], 'comentario' => ['comment'],
        'satisfaccion' => ['satisfaction', 'csat'],
        'abandono' => ['churn'], 'entregas' => ['deliveries', 'shipments'], 'entrega' => ['delivery'],
        'envios' => ['shipments', 'shipping'], 'envio' => ['shipment'],
        'raiz' => ['root'], 'raices' => ['root'],
        'pendientes' => ['backlog', 'open'], 'pendiente' => ['backlog', 'open'],
        'abiertos' => ['open'], 'abierto' => ['open'],
        'cerrados' => ['closed'], 'cerrado' => ['closed'], 'cierre' => ['closed', 'close'],
        'reabiertos' => ['reopened'], 'reabierto' => ['reopened'], 'reaperturas' => ['reopened'],
        'resueltos' => ['resolved', 'resolution'], 'resuelto' => ['resolved', 'resolution'],
        'incompletos' => ['incomplete'], 'incompleto' => ['incomplete'],
    ];

    /**
     * The word set PLUS every translation either direction — deduped,
     * lowercase-ascii like the matchers expect.
     *
     * @param  Collection<int, string>  $words
     * @return Collection<int, string>
     */
    public static function expand(Collection $words): Collection
    {
        static $enEs = null;
        if ($enEs === null) {
            $enEs = [];
            foreach (self::ES_EN as $es => $ens) {
                foreach ($ens as $en) {
                    $enEs[$en][] = $es;
                }
            }
        }

        return $words
            ->flatMap(fn (string $w): array => [
                $w,
                ...(self::ES_EN[$w] ?? []),
                ...($enEs[$w] ?? []),
            ])
            ->unique()->values();
    }
}
