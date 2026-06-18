package org.example.project

import io.ktor.client.request.forms.*
import io.ktor.http.*
import io.ktor.client.*
import io.ktor.client.call.*
import io.ktor.client.plugins.contentnegotiation.*
import io.ktor.client.request.*
import io.ktor.serialization.kotlinx.json.*
import kotlinx.serialization.Serializable
import kotlinx.serialization.json.Json

// ── Data classes ─────────────────────────────────────────────

@Serializable
data class LoginRequest(val email: String, val password: String)

@Serializable
data class LoginResponse(
    val success      : Boolean,
    val rol          : String  = "",
    val nombre       : String  = "",
    val mensaje      : String  = "",
    val fotoPerfil   : String? = null,
    val pagoPendiente: Boolean = false  // aviso sin bloquear acceso
)

@Serializable
data class ReporteRequest(
    val usuarioEmail : String,
    val usuarioNombre: String,
    val tipo         : String,
    val nivel        : Int,
    val descripcion  : String? = null,
    val fotoUrl      : String? = null,
    val latitud      : Double? = null,
    val longitud     : Double? = null
)

@Serializable
data class ReporteResponse(
    val success: Boolean,
    val mensaje: String = ""
)

@Serializable
data class CodigoResponse(
    val success: Boolean,
    val mensaje: String = ""
)

@Serializable
data class EstadoAccesoResponse(
    val success      : Boolean,
    val nombre       : String = "",
    val email        : String = "",
    val numeroCasa   : String = "",
    val cerrada      : String = "",
    val estadoPago   : String = "",
    val siguienteTipo: String = "",
    val mensaje      : String = ""
)

@Serializable
data class RegistrarAccesoResponse(
    val success: Boolean,
    val mensaje: String = "",
    val nombre : String = "",
    val tipo   : String = "",
    val fecha  : String = ""
)

// ── Cliente HTTP ─────────────────────────────────────────────

val httpClient = HttpClient {
    install(ContentNegotiation) {
        json(Json {
            ignoreUnknownKeys = true  // evita crash si el backend manda campos nuevos
            isLenient          = true
        })
    }
}

// ── BASE URL ─────────────────────────────────────────────────

const val BASE_URL = "https://vecinet-production.up.railway.app"

/*
IPs locales (desarrollo)
10.253.24.223 Telcel cac
192.168.1.78  casa 5g
*/

// ── Funciones de API ─────────────────────────────────────────

suspend fun login(email: String, password: String): LoginResponse {
    return httpClient.post("$BASE_URL/login") {
        contentType(ContentType.Application.Json)
        setBody(LoginRequest(email, password))
    }.body()
}

suspend fun enviarReporte(request: ReporteRequest): ReporteResponse {
    return httpClient.post("$BASE_URL/reporte") {
        contentType(ContentType.Application.Json)
        setBody(request)
    }.body()
}

suspend fun validarCodigo(codigo: String, email: String): CodigoResponse {
    return try {
        httpClient.post("$BASE_URL/codigos/validar") {
            contentType(ContentType.Application.Json)
            setBody("""{"codigo":"$codigo","email":"$email"}""")
        }.body()
    } catch (e: Exception) {
        CodigoResponse(success = false, mensaje = "Error de conexion")
    }
}

suspend fun subirFoto(imagenBytes: ByteArray, nombreArchivo: String): String? {
    return try {
        val response = httpClient.post("$BASE_URL/upload") {
            setBody(
                MultiPartFormDataContent(
                    formData {
                        append("foto", imagenBytes, Headers.build {
                            append(HttpHeaders.ContentType, "image/jpeg")
                            append(HttpHeaders.ContentDisposition, "filename=$nombreArchivo")
                        })
                    }
                )
            )
        }
        val body = response.body<Map<String, String>>()
        body["url"]
    } catch (e: Exception) {
        null
    }
}

suspend fun subirFotoPerfil(email: String, imagenBytes: ByteArray): String? {
    return try {
        val response = httpClient.post("$BASE_URL/perfil/foto") {
            setBody(
                MultiPartFormDataContent(
                    formData {
                        append("email", email)
                        append("foto", imagenBytes, Headers.build {
                            append(HttpHeaders.ContentType, "image/jpeg")
                            append(HttpHeaders.ContentDisposition, "filename=perfil.jpg")
                        })
                    }
                )
            )
        }
        val body = response.body<Map<String, String>>()
        body["url"]
    } catch (e: Exception) {
        null
    }
}

suspend fun registrarToken(email: String, token: String) {
    try {
        httpClient.post("$BASE_URL/usuarios/token") {
            contentType(ContentType.Application.Json)
            setBody("""{"email":"$email","token":"$token"}""")
        }
    } catch (e: Exception) { }
}

suspend fun verificarEstadoAcceso(email: String): EstadoAccesoResponse {
    return httpClient.get("$BASE_URL/vigilancia/estado/$email").body()
}

suspend fun registrarAcceso(
    emailResidente: String,
    tipo          : String,
    registradoPor : String
): RegistrarAccesoResponse {
    return httpClient.post("$BASE_URL/bitacora/registrar") {
        contentType(ContentType.Application.Json)
        setBody(mapOf(
            "emailResidente" to emailResidente,
            "tipo"           to tipo,
            "registradoPor"  to registradoPor
        ))
    }.body()
}