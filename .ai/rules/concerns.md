---
paths:
    - 'app/Servicios/ServicioCascadaSuspension.php,app/Servicios/ServicioOperatividad.php,app/Models/Suspension.php,app/Http/Controllers/{Empresa,Sucursal,Activo}Controller.php,app/Http/Controllers/Concerns/ReactivaSuspendidos.php'
---

# Concerns

## Cascada de desactivación no destructiva + blindaje multicausa (Fase 7)

Al desactivar Empresa/Sucursal/Activo, `ServicioCascadaSuspension::suspender()` pone `activo=false` en sus dependientes que estaban activos y deja una fila en `suspensiones` (nunca crea fila para algo ya inactivo por otra causa). Matriz: Empresa → Sucursales+Colaboradores+Áreas+Activos+Conjuntos (NUNCA Almacenes, N:M compartidos); Sucursal → sus Colaboradores; Activo → Conjuntos que lo usan como componente. Área y Almacén no cascadan a nada.

BLINDAJE MULTICAUSA: una entidad puede depender de más de una precondición para operar de verdad (Colaborador de su Empresa Y su Sucursal; Conjunto de su Empresa Y de TODOS sus Activos componentes). `App\Servicios\ServicioOperatividad` es la ÚNICA fuente de verdad para "¿está esto realmente operativo?" (`esOperativo()`) y "¿qué le falta?" (`dependenciasNoOperativas()`, ignora a propósito el flag propio de la entidad porque es justo lo que se está a punto de cambiar). `ServicioCascadaSuspension::reactivar()`/`reactivarSeleccionados()` SIEMPRE revalidan contra esto antes de flip el flag — si aún depende de algo inactivo, la suspensión se conserva y se informa el motivo en `omitidos` (nunca un 200 silencioso que deja un estado inválido). El checklist (`checklistDe()` + `paraVista()`, UI `PanelSuspendidos.vue`) muestra proactivamente `puede_reactivarse` + `motivos` por fila para que el usuario no lo descubra por ensayo y error.

Al añadir un nuevo tipo cascada-dependiente: sumar su caso a `ServicioOperatividad::dependenciasNoOperativas()`, nunca reimplementar la regla en el controller ni en el frontend.
