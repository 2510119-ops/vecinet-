package org.example.project

data class SesionUsuario(
    val nombre: String,
    val email : String,
    val rol   : String,
    val foto  : String?
)

expect object SessionManager {
    suspend fun guardarSesion(nombre: String, email: String, rol: String, foto: String?)
    suspend fun obtenerSesion(): SesionUsuario?
    suspend fun cerrarSesion()
}