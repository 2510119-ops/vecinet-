package org.example.project

data class Ubicacion(val latitud: Double, val longitud: Double)

expect class LocationService() {
    suspend fun obtenerUbicacion(): Ubicacion?
}