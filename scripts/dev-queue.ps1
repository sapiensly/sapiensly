# Local dev queue supervisor (Windows).
#
# queue:listen runs each job batch in a child `queue:work --once` process and
# kills the child when it outlives --timeout. On Windows (no pcntl) the jobs'
# own $timeout properties never fire, so that parent-process timeout is the
# ONLY working kill-switch for a hung job — but the ProcessTimedOutException
# it throws also crashes queue:listen itself, leaving the dev queue dead while
# reverb/vite keep running. This loop restarts the listener so a killed hung
# child costs seconds, not a silent dead queue; Redis re-pops the reserved job
# after retry_after (900s).
#
# --timeout=850: above the longest job timeout (ExpressAppJob, 600s) so
# legitimately long builder turns are not cut short, below retry_after (900s)
# so a killed job cannot overlap its own retry.

$queues = 'default,ai,debate,agent-responses,workflows,whatsapp-webhooks,whatsapp-outbound'

while ($true) {
    php artisan queue:listen "--queue=$queues" --tries=1 --timeout=850
    Write-Host "[dev-queue] queue:listen exited (code $LASTEXITCODE); restarting in 2s..."
    Start-Sleep -Seconds 2
}
