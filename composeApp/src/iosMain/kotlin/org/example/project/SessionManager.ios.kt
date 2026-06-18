package org.example.project

actual object SessionManager {
    actual suspend fun guardarSesion(nombre: String, email: String, rol: String, foto: String?) {}
    actual suspend fun obtenerSesion(): SesionUsuario? = null
    actual suspend fun cerrarSesion() {}
}