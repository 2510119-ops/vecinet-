package org.example.project

import androidx.compose.runtime.Composable

@Composable
actual fun rememberGpsActivator(
    onGpsListo: () -> Unit,
    onGpsNoDisponible: () -> Unit
): () -> Unit {
    val locationService = LocationService()
    return {
        val activity = MainActivity.instance
        if (activity != null) {
            if (locationService.isLocationEnabled()) {
                onGpsListo()
            } else {
                locationService.solicitarActivarGPS(activity) { activado: Boolean ->
                    if (activado) onGpsListo() else onGpsNoDisponible()
                }
            }
        } else {
            onGpsNoDisponible()
        }
    }
}