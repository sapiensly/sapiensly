<?php

/**
 * The app-builder benchmark suite.
 *
 * Every case is an app description a person could plausibly write, paired with
 * what a good answer looks like. The expectations are authored BY HAND and
 * reviewed — never derived from a generated app, which would be letting the
 * examinee set the exam. When a case's expectation is wrong, fix the
 * expectation here on purpose; do not relax it because a run went red.
 *
 * `expect.objects` are the entities the description plainly asks the app to
 * hold. `expect.dashboard` are the objects whose questions the description
 * asks to SEE — the last paragraph of most of these — and the dashboard has to
 * surface each one, as a KPI or as a chart.
 *
 * Names are matched loosely (accents folded, singular/plural tolerated) and in
 * the language of the description: a Spanish brief that comes back with an
 * object called "Leases" has drifted, and that is a finding, not a pass.
 *
 * @return list<array{key: string, name: string, locale: string, description: string, expect: array{objects: list<string>, dashboard: list<string>}}>
 */
return [
    [
        'key' => 'arrendamientos',
        'name' => 'Arrendamientos',
        'locale' => 'es-MX',
        'description' => <<<'TXT'
        Administración de arrendamiento de inmuebles para una operadora que gestiona propiedades de terceros.

        PROPIETARIOS: nombre completo, RFC, correo electrónico, teléfono, tipo de persona (física, moral), cuenta bancaria para depósitos, y porcentaje de comisión que cobra la operadora.

        INMUEBLES: cada inmueble pertenece a un propietario. Clave del inmueble, dirección completa, colonia, ciudad, tipo de inmueble (casa, departamento, local comercial, bodega, oficina), superficie en metros cuadrados, número de recámaras, número de baños, renta mensual, mantenimiento mensual, estado del inmueble (disponible, rentado, en mantenimiento, fuera de servicio), y fecha de alta en el catálogo.

        INQUILINOS: nombre completo, correo electrónico, teléfono, fecha de nacimiento, ocupación, e ingreso mensual comprobable.

        CONTRATOS: cada contrato es de un inmueble y de un inquilino. Folio del contrato, fecha de inicio de vigencia, fecha de fin de vigencia, fecha de firma, renta mensual pactada, depósito en garantía, día de corte para el pago, estado del contrato (borrador, vigente, por vencer, vencido, rescindido) y notas.

        PAGOS: cada pago corresponde a un contrato. Folio del recibo, mes que cubre, monto pagado, fecha de pago, fecha límite de pago, método de pago (transferencia, efectivo, cheque, domiciliación) y estado del pago (pendiente, pagado, vencido, parcial).

        INCIDENCIAS: cada incidencia es sobre un inmueble. Folio, descripción del problema reportado, fecha de reporte, fecha compromiso de atención, prioridad (baja, media, alta, urgente), estado (reportada, en revisión, en reparación, resuelta, cancelada) y costo total de la reparación.

        La operadora necesita ver qué contratos están por vencer, la cobranza del mes, qué inmuebles están disponibles, y el estado de las incidencias abiertas.
        TXT,
        'expect' => [
            'objects' => ['propietarios', 'inmuebles', 'inquilinos', 'contratos', 'pagos', 'incidencias'],
            'dashboard' => ['contratos', 'pagos', 'inmuebles', 'incidencias'],
        ],
    ],
    [
        'key' => 'taller',
        'name' => 'Taller mecánico',
        'locale' => 'es-MX',
        'description' => <<<'TXT'
        Taller mecánico que atiende autos de clientes particulares y de flotillas.

        CLIENTES: nombre, teléfono, correo, tipo de cliente (particular, flotilla) y RFC.

        VEHICULOS: cada vehículo pertenece a un cliente. Placas, marca, modelo, año, kilometraje actual y color.

        ORDENES DE SERVICIO: cada orden es sobre un vehículo. Folio, fecha de recepción, fecha prometida de entrega, kilometraje de entrada, descripción de la falla reportada, estado (recibida, diagnosticada, en reparación, esperando refacción, terminada, entregada), prioridad y total de la orden.

        REFACCIONES USADAS: cada refacción pertenece a una orden de servicio. Nombre de la pieza, número de parte, cantidad y costo unitario.

        MECANICOS: nombre, especialidad y teléfono.

        El jefe de taller quiere ver cuántas órdenes hay en cada estado, cuáles se van a pasar de la fecha prometida, y cuánto se está facturando.
        TXT,
        'expect' => [
            'objects' => ['clientes', 'vehiculos', 'ordenes', 'refacciones', 'mecanicos'],
            'dashboard' => ['ordenes'],
        ],
    ],
    [
        'key' => 'clinica',
        'name' => 'Clínica dental',
        'locale' => 'es-MX',
        'description' => <<<'TXT'
        Clínica dental con varios consultorios.

        PACIENTES: nombre completo, fecha de nacimiento, teléfono, correo, alergias conocidas y si tiene seguro médico.

        DENTISTAS: nombre, cédula profesional, especialidad y teléfono.

        CITAS: cada cita es de un paciente con un dentista. Fecha y hora de la cita, motivo, consultorio, duración estimada en minutos y estado (agendada, confirmada, en curso, atendida, cancelada, no asistió).

        TRATAMIENTOS: cada tratamiento se realiza en una cita. Nombre del tratamiento, pieza dental, costo y si ya fue cobrado.

        La recepción necesita ver la agenda del día, cuántas citas se cancelan o no asisten, y cuánto se ha cobrado en tratamientos.
        TXT,
        'expect' => [
            'objects' => ['pacientes', 'dentistas', 'citas', 'tratamientos'],
            'dashboard' => ['citas', 'tratamientos'],
        ],
    ],
    [
        'key' => 'reclutamiento',
        'name' => 'Reclutamiento',
        'locale' => 'es-MX',
        'description' => <<<'TXT'
        Proceso de reclutamiento para una empresa de tecnología.

        VACANTES: título del puesto, área, nivel (junior, semi senior, senior, lead), modalidad (remoto, híbrido, presencial), sueldo ofrecido, fecha de apertura, fecha objetivo de cierre y estado (abierta, en pausa, cubierta, cancelada).

        CANDIDATOS: nombre, correo, teléfono, LinkedIn, años de experiencia y expectativa de sueldo.

        POSTULACIONES: cada postulación es de un candidato a una vacante. Fecha de postulación, fuente (referido, portal, LinkedIn, feria), etapa (aplicó, filtro telefónico, entrevista técnica, entrevista final, oferta, contratado, descartado) y notas.

        ENTREVISTAS: cada entrevista pertenece a una postulación. Fecha, tipo, entrevistador y calificación del 1 al 5.

        Recursos Humanos quiere ver cuántas vacantes siguen abiertas, en qué etapa está cada postulación y cuáles vacantes ya se pasaron de su fecha objetivo.
        TXT,
        'expect' => [
            'objects' => ['vacantes', 'candidatos', 'postulaciones', 'entrevistas'],
            'dashboard' => ['vacantes', 'postulaciones'],
        ],
    ],
    [
        'key' => 'inventario',
        'name' => 'Inventario y compras',
        'locale' => 'es-MX',
        'description' => <<<'TXT'
        Control de inventario y compras de una distribuidora de material eléctrico.

        PROVEEDORES: razón social, RFC, contacto, teléfono, correo y días de crédito.

        PRODUCTOS: código, descripción, unidad de medida, costo, precio de venta, existencia actual y punto de reorden.

        ORDENES DE COMPRA: cada orden es a un proveedor. Folio, fecha de emisión, fecha estimada de llegada, estado (borrador, enviada, confirmada, recibida parcial, recibida, cancelada) y total.

        PARTIDAS DE COMPRA: cada partida pertenece a una orden de compra y a un producto. Cantidad pedida, cantidad recibida y costo unitario.

        ALMACENES: nombre, dirección y responsable.

        Compras necesita ver qué órdenes están por llegar, cuáles se retrasaron y qué productos están por debajo de su punto de reorden.
        TXT,
        'expect' => [
            'objects' => ['proveedores', 'productos', 'ordenes', 'partidas', 'almacenes'],
            'dashboard' => ['ordenes', 'productos'],
        ],
    ],
    [
        'key' => 'field_service',
        'name' => 'Field Service',
        'locale' => 'en-US',
        'description' => <<<'TXT'
        Field service operation for a company that installs and maintains commercial HVAC equipment.

        CUSTOMERS: company name, contact person, phone, email, billing address and account type (contract, on-demand).

        SITES: each site belongs to a customer. Site name, address, access notes and time zone.

        EQUIPMENT: each unit is installed at a site. Serial number, manufacturer, model, install date, warranty end date and condition (good, needs attention, failing, decommissioned).

        WORK ORDERS: each work order is for one piece of equipment. Number, opened date, promised date, type (install, preventive, repair, inspection), priority, status (new, scheduled, in progress, on hold, completed, invoiced) and total billed.

        TECHNICIANS: name, certification level, phone and home region.

        Dispatch needs to see which work orders are past their promised date, how much is being billed, and which equipment is failing.
        TXT,
        'expect' => [
            'objects' => ['customers', 'sites', 'equipment', 'work orders', 'technicians'],
            'dashboard' => ['work orders', 'equipment'],
        ],
    ],
    [
        'key' => 'escola',
        'name' => 'Escola de idiomas',
        'locale' => 'pt-BR',
        'description' => <<<'TXT'
        Escola de idiomas com turmas presenciais e online.

        ALUNOS: nome completo, e-mail, telefone, data de nascimento e nível atual.

        PROFESSORES: nome, idiomas que leciona, telefone e e-mail.

        TURMAS: cada turma tem um professor. Nome da turma, idioma, nível, modalidade (presencial, online), dia e horário, data de início, data de término e situação (planejada, em andamento, concluída, cancelada).

        MATRICULAS: cada matrícula é de um aluno em uma turma. Data da matrícula, valor mensal, forma de pagamento e situação (ativa, trancada, cancelada, concluída).

        MENSALIDADES: cada mensalidade pertence a uma matrícula. Mês de referência, valor, data de vencimento, data de pagamento e situação (em aberto, paga, atrasada).

        A secretaria precisa ver quais mensalidades estão atrasadas, quantos alunos estão matriculados por turma e quais turmas estão em andamento.
        TXT,
        'expect' => [
            'objects' => ['alunos', 'professores', 'turmas', 'matriculas', 'mensalidades'],
            'dashboard' => ['mensalidades', 'turmas', 'matriculas'],
        ],
    ],
    [
        'key' => 'eventos',
        'name' => 'Producción de eventos',
        'locale' => 'es-MX',
        'description' => <<<'TXT'
        Productora de eventos corporativos.

        CLIENTES: empresa, contacto, teléfono, correo y giro.

        EVENTOS: cada evento es de un cliente. Nombre del evento, sede, fecha, hora de inicio, número de asistentes esperados, presupuesto aprobado y estado (cotizado, confirmado, en montaje, realizado, cancelado).

        PROVEEDORES: nombre, servicio que presta, teléfono y correo.

        CONTRATACIONES: cada contratación es de un proveedor para un evento. Concepto, costo acordado, anticipo pagado y estado (solicitada, confirmada, pagada).

        TAREAS: cada tarea pertenece a un evento. Descripción, responsable, fecha límite y estado (pendiente, en proceso, terminada).

        La productora quiere ver qué eventos vienen en las próximas semanas, qué tareas están vencidas y cuánto se lleva gastado por evento.
        TXT,
        'expect' => [
            'objects' => ['clientes', 'eventos', 'proveedores', 'contrataciones', 'tareas'],
            'dashboard' => ['eventos', 'tareas', 'contrataciones'],
        ],
    ],
    [
        'key' => 'veterinaria',
        'name' => 'Veterinaria',
        'locale' => 'es-MX',
        'description' => <<<'TXT'
        Clínica veterinaria de pequeñas especies.

        DUEÑOS: nombre, teléfono, correo y dirección.

        MASCOTAS: cada mascota tiene un dueño. Nombre, especie, raza, fecha de nacimiento, sexo, peso y si está esterilizada.

        CONSULTAS: cada consulta es de una mascota. Fecha, motivo, diagnóstico, tratamiento indicado, costo y estado (agendada, atendida, cancelada).

        VACUNAS: cada vacuna aplicada es de una mascota. Tipo de vacuna, fecha de aplicación, fecha de próxima dosis y lote.

        La clínica necesita ver qué vacunas vencen este mes, cuántas consultas se atienden y cuánto se factura.
        TXT,
        'expect' => [
            'objects' => ['duenos', 'mascotas', 'consultas', 'vacunas'],
            'dashboard' => ['vacunas', 'consultas'],
        ],
    ],
    [
        'key' => 'restaurante',
        'name' => 'Restaurante',
        'locale' => 'es-MX',
        'description' => <<<'TXT'
        Restaurante con servicio en mesa y para llevar.

        PLATILLOS: nombre, categoría (entrada, fuerte, postre, bebida), precio, tiempo de preparación en minutos y si está disponible.

        MESEROS: nombre, turno y teléfono.

        COMANDAS: cada comanda la levanta un mesero. Folio, mesa, tipo de servicio (mesa, para llevar), hora de apertura, estado (abierta, en cocina, servida, pagada, cancelada) y forma de pago.

        RENGLONES DE COMANDA: cada renglón pertenece a una comanda y a un platillo. Cantidad, precio unitario y notas para la cocina.

        El gerente quiere ver cuánto se vendió, qué comandas siguen abiertas y qué platillos se piden más.
        TXT,
        'expect' => [
            'objects' => ['platillos', 'meseros', 'comandas', 'renglones'],
            'dashboard' => ['comandas'],
        ],
    ],
];
