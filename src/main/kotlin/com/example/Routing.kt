package com.example.plugins

import com.google.auth.oauth2.GoogleCredentials
import org.mindrot.jbcrypt.BCrypt
import org.jetbrains.exposed.sql.*
import org.jetbrains.exposed.sql.SqlExpressionBuilder.eq
import org.jetbrains.exposed.sql.transactions.transaction
import io.ktor.http.*
import io.ktor.http.content.*
import io.ktor.server.application.*
import io.ktor.server.request.*
import io.ktor.server.response.*
import io.ktor.server.routing.*
import io.ktor.utils.io.toByteArray
import kotlinx.serialization.Serializable
import kotlinx.serialization.json.Json
import kotlinx.serialization.json.JsonElement
import kotlinx.serialization.json.contentOrNull
import kotlinx.serialization.json.intOrNull
import kotlinx.serialization.json.jsonPrimitive
import java.io.File
import java.net.HttpURLConnection
import java.net.URL
import java.time.LocalDateTime
import java.time.format.DateTimeFormatter

@Serializable
data class LoginRequest(val email: String, val password: String)

@Serializable
data class LoginResponse(
    val success      : Boolean,
    val rol          : String  = "",
    val nombre       : String  = "",
    val mensaje      : String  = "",
    val fotoPerfil   : String? = null,
    val pagoPendiente: Boolean = false
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

private const val BASE_URL     = "https://vecinet-production.up.railway.app"
private const val CHARS_CODIGO = "ABCDEFGHJKLMNPQRSTUVWXYZ23456789"

private fun generarCodigoConVencimiento(emailAsignado: String? = null, nombreAsignado: String? = null, generadoPor: String = "Sistema"): Map<String, String> {
    val codigo = (1..9).map { CHARS_CODIGO.random() }.joinToString("")
    val ahora = LocalDateTime.now()
    val fechaCreacion = ahora.format(DateTimeFormatter.ofPattern("yyyy-MM-dd HH:mm:ss"))
    val fechaVencimiento = ahora.plusDays(90).format(DateTimeFormatter.ofPattern("yyyy-MM-dd HH:mm:ss"))
    transaction {
        CodigosAcceso.insert {
            it[CodigosAcceso.codigo] = codigo
            it[CodigosAcceso.generadoPor] = generadoPor
            it[CodigosAcceso.emailAsignado] = emailAsignado?.ifBlank { null }
            it[CodigosAcceso.nombreAsignado] = nombreAsignado?.ifBlank { null }
            it[CodigosAcceso.fechaCreacion] = fechaCreacion
            it[CodigosAcceso.fechaVencimiento] = fechaVencimiento
            it[CodigosAcceso.estado] = "activo"
        }
    }
    return mapOf(
        "codigo" to codigo,
        "fechaCreacion" to fechaCreacion,
        "fechaVencimiento" to fechaVencimiento,
        "estado" to "activo"
    )
}

private fun verificarPagosVencidos() {
    val ahora = LocalDateTime.now().format(DateTimeFormatter.ofPattern("yyyy-MM-dd HH:mm:ss"))
    transaction {
        val vencidos = PagosVigilancia.select {
            (PagosVigilancia.estado eq "vigente") and (PagosVigilancia.fechaVencimiento.isNotNull())
        }.filter { row ->
            val fv = row[PagosVigilancia.fechaVencimiento]
            fv != null && fv < ahora
        }.map { it[PagosVigilancia.emailResidente] }
        vencidos.forEach { email ->
            PagosVigilancia.update({ PagosVigilancia.emailResidente eq email }) {
                it[PagosVigilancia.estado] = "pendiente"
            }
        }
    }
}

private fun enviarFCM(tokens: List<String>, titulo: String, cuerpo: String, esRojo: Boolean) {
    if (tokens.isEmpty()) return
    val serviceAccountJson = System.getenv("FCM_SERVICE_ACCOUNT") ?: return
    val projectId = "vecinet-43a98"
    try {
        val credentials = GoogleCredentials
            .fromStream(serviceAccountJson.byteInputStream())
            .createScoped(listOf("https://www.googleapis.com/auth/firebase.messaging"))
        credentials.refreshIfExpired()
        val accessToken = credentials.accessToken.tokenValue
        tokens.forEach { token ->
            val channelId = if (esRojo) "vecinet_rojo" else "vecinet_normal"
            val sound     = if (esRojo) "alarma" else "default"
            val body = """
            {
                "message": {
                    "token": "$token",
                    "notification": { "title": "$titulo", "body": "$cuerpo" },
                    "data": { "esRojo": "$esRojo", "channelId": "$channelId" },
                    "android": {
                        "priority": "high",
                        "notification": { "sound": "$sound", "channel_id": "$channelId" }
                    }
                }
            }
            """.trimIndent()
            val conn = URL("https://fcm.googleapis.com/v1/projects/$projectId/messages:send")
                .openConnection() as HttpURLConnection
            conn.requestMethod = "POST"
            conn.setRequestProperty("Authorization", "Bearer $accessToken")
            conn.setRequestProperty("Content-Type", "application/json")
            conn.doOutput = true
            conn.outputStream.write(body.toByteArray())
            conn.responseCode
            conn.disconnect()
        }
    } catch (e: Exception) { /* silencioso */ }
}

fun Application.configureRouting() {
    routing {

        get("/") { call.respondText("Servidor Vecinet corriendo") }

        // ── LOGIN ────────────────────────────────────────────────
        post("/login") {
            try {
                val request = Json.decodeFromString<LoginRequest>(call.receiveText())
                Thread { verificarPagosVencidos() }.start()
                var resultado: LoginResponse? = null
                transaction {
                    val usuario = Usuarios.select { Usuarios.email eq request.email }.firstOrNull()
                    if (usuario != null && BCrypt.checkpw(request.password, usuario[Usuarios.password])) {
                        val pago = PagosVigilancia.select { PagosVigilancia.emailResidente eq request.email }.firstOrNull()
                        val pagoPendiente = pago?.get(PagosVigilancia.estado) == "pendiente"
                        resultado = LoginResponse(
                            success = true, rol = usuario[Usuarios.rol], nombre = usuario[Usuarios.nombre],
                            fotoPerfil = usuario[Usuarios.fotoPerfil], pagoPendiente = pagoPendiente
                        )
                    } else if (usuario == null) {
                        val visitante = Visitantes.select { Visitantes.email eq request.email }.firstOrNull()
                        if (visitante != null && BCrypt.checkpw(request.password, visitante[Visitantes.password])) {
                            resultado = LoginResponse(
                                success = true, rol = visitante[Visitantes.rol],
                                nombre = visitante[Visitantes.nombre], fotoPerfil = visitante[Visitantes.fotoPerfil]
                            )
                        }
                    }
                }
                resultado?.let { call.respond(it) } ?: call.respond(
                    HttpStatusCode.Unauthorized, LoginResponse(success = false, mensaje = "Credenciales incorrectas"))
            } catch (e: Exception) {
                call.respond(HttpStatusCode.BadRequest, LoginResponse(success = false, mensaje = "Error: ${e.message}"))
            }
        }

        // ── CREAR REPORTE + NOTIFICACION FCM ────────────────────
        post("/reporte") {
            try {
                val request = Json.decodeFromString<ReporteRequest>(call.receiveText())
                val fecha   = LocalDateTime.now().format(DateTimeFormatter.ofPattern("yyyy-MM-dd HH:mm:ss"))
                transaction {
                    Reportes.insert {
                        it[usuarioEmail] = request.usuarioEmail; it[usuarioNombre] = request.usuarioNombre
                        it[tipo] = request.tipo; it[nivel] = request.nivel
                        it[descripcion] = request.descripcion; it[fotoUrl] = request.fotoUrl
                        it[latitud] = request.latitud; it[longitud] = request.longitud
                        it[Reportes.fecha] = fecha
                    }
                }
                val cerradaReportante = transaction {
                    Usuarios.select { Usuarios.email eq request.usuarioEmail }.firstOrNull()?.get(Usuarios.cerrada)
                }
                val tokens = transaction {
                    Usuarios.selectAll().mapNotNull { row ->
                        val token = row[Usuarios.fcmToken] ?: return@mapNotNull null
                        if (token.isBlank()) return@mapNotNull null
                        val rol            = row[Usuarios.rol].lowercase()
                        val cerradaUsuario = row[Usuarios.cerrada]
                        val esPrivilegiado = rol.contains("presidente") || rol.contains("comite") || rol.contains("guardia")
                        when {
                            esPrivilegiado -> token
                            !cerradaReportante.isNullOrBlank() && cerradaUsuario == cerradaReportante -> token
                            cerradaReportante.isNullOrBlank() -> token
                            else -> null
                        }
                    }
                }
                val esRojo = request.nivel == 3
                val titulo = when (request.nivel) {
                    3    -> "\uD83D\uDD34 EMERGENCIA ALTA"
                    2    -> "\uD83D\uDFE1 EMERGENCIA MEDIA"
                    else -> "\uD83D\uDFE2 Reporte vecinal"
                }
                Thread { enviarFCM(tokens, titulo, "${request.usuarioNombre}: ${request.tipo}", esRojo) }.start()
                call.respond(ReporteResponse(success = true, mensaje = "Reporte guardado"))
            } catch (e: Exception) {
                call.respond(HttpStatusCode.BadRequest, ReporteResponse(success = false, mensaje = "Error: ${e.message}"))
            }
        }

        // ── SUBIR FOTO DE REPORTE ────────────────────────────────
        post("/upload") {
            try {
                val multipart = call.receiveMultipart(); var fileName = ""
                multipart.forEachPart { part ->
                    if (part is PartData.FileItem) {
                        fileName = "foto_${System.currentTimeMillis()}.jpg"
                        val file = File("uploads/$fileName"); file.parentFile?.mkdirs()
                        file.writeBytes(part.provider().toByteArray())
                    }
                    part.dispose()
                }
                if (fileName.isNotEmpty()) call.respond(mapOf("url" to "$BASE_URL/fotos/$fileName"))
                else call.respond(HttpStatusCode.BadRequest, mapOf("error" to "No se recibio archivo"))
            } catch (e: Exception) { call.respond(HttpStatusCode.BadRequest, mapOf("error" to e.message)) }
        }

        // ── SUBIR FOTO DE PERFIL ─────────────────────────────────
        post("/perfil/foto") {
            try {
                val multipart = call.receiveMultipart(); var fileName = ""; var email = ""
                multipart.forEachPart { part ->
                    when (part) {
                        is PartData.FormItem -> if (part.name == "email") email = part.value
                        is PartData.FileItem -> {
                            fileName = "perfil_${System.currentTimeMillis()}.jpg"
                            val file = File("uploads/$fileName"); file.parentFile?.mkdirs()
                            file.writeBytes(part.provider().toByteArray())
                        }
                        else -> Unit
                    }
                    part.dispose()
                }
                if (fileName.isNotEmpty() && email.isNotEmpty()) {
                    val url = "$BASE_URL/fotos/$fileName"
                    transaction {
                        val updated = Usuarios.update({ Usuarios.email eq email }) { it[fotoPerfil] = url }
                        if (updated == 0) Visitantes.update({ Visitantes.email eq email }) { it[fotoPerfil] = url }
                    }
                    call.respond(mapOf("url" to url))
                } else call.respond(HttpStatusCode.BadRequest, mapOf("error" to "Faltan datos"))
            } catch (e: Exception) { call.respond(HttpStatusCode.BadRequest, mapOf("error" to e.message)) }
        }

        // ── SERVIR FOTOS ─────────────────────────────────────────
        get("/fotos/{nombre}") {
            val nombre = call.parameters["nombre"] ?: return@get call.respond(HttpStatusCode.NotFound)
            val file = File("uploads/$nombre")
            if (file.exists()) call.respondFile(file) else call.respond(HttpStatusCode.NotFound)
        }

        // ── LISTAR REPORTES ──────────────────────────────────────
        get("/reportes") {
            try {
                val lista = transaction {
                    Reportes.selectAll().orderBy(Reportes.fecha, SortOrder.DESC).map {
                        mapOf(
                            "id" to it[Reportes.id].toString(), "usuarioEmail" to it[Reportes.usuarioEmail],
                            "usuarioNombre" to it[Reportes.usuarioNombre], "tipo" to it[Reportes.tipo],
                            "nivel" to it[Reportes.nivel].toString(), "descripcion" to (it[Reportes.descripcion] ?: ""),
                            "fotoUrl" to (it[Reportes.fotoUrl] ?: ""),
                            "latitud" to (it[Reportes.latitud]?.toString() ?: ""),
                            "longitud" to (it[Reportes.longitud]?.toString() ?: ""),
                            "fecha" to it[Reportes.fecha], "estado" to it[Reportes.estado]
                        )
                    }
                }
                call.respond(lista)
            } catch (e: Exception) { call.respond(HttpStatusCode.InternalServerError, "Error: ${e.message}") }
        }

        // ── RESOLVER REPORTE ─────────────────────────────────────
        put("/reportes/{id}/resolver") {
            try {
                val id = call.parameters["id"]?.toIntOrNull()
                    ?: return@put call.respond(HttpStatusCode.BadRequest, mapOf("success" to "false"))
                transaction { Reportes.update({ Reportes.id eq id }) { it[estado] = "resuelto" } }
                call.respond(mapOf("success" to "true", "mensaje" to "Reporte marcado como resuelto"))
            } catch (e: Exception) {
                call.respond(HttpStatusCode.BadRequest, mapOf("success" to "false", "mensaje" to "Error: ${e.message}"))
            }
        }

        // ── LISTAR USUARIOS ──────────────────────────────────────
        get("/usuarios") {
            try {
                val lista = transaction {
                    Usuarios.selectAll().map {
                        mapOf(
                            "id" to it[Usuarios.id].toString(), "nombre" to it[Usuarios.nombre],
                            "email" to it[Usuarios.email], "telefono" to it[Usuarios.telefono],
                            "rol" to it[Usuarios.rol], "cerrada" to (it[Usuarios.cerrada] ?: ""),
                            "fotoPerfil" to (it[Usuarios.fotoPerfil] ?: ""),
                            "numeroCasa" to (it[Usuarios.numeroCasa]?.toString() ?: "")
                        )
                    }
                }
                call.respond(lista)
            } catch (e: Exception) { call.respond(HttpStatusCode.InternalServerError, "Error: ${e.message}") }
        }

        // ── GUARDAR TOKEN FCM ────────────────────────────────────
        post("/usuarios/token") {
            try {
                val body  = Json.decodeFromString<Map<String, String>>(call.receiveText())
                val email = body["email"] ?: return@post call.respond(HttpStatusCode.BadRequest, mapOf("success" to "false", "mensaje" to "Falta email"))
                val token = body["token"] ?: return@post call.respond(HttpStatusCode.BadRequest, mapOf("success" to "false", "mensaje" to "Falta token"))
                transaction { Usuarios.update({ Usuarios.email eq email }) { it[Usuarios.fcmToken] = token } }
                call.respond(mapOf("success" to "true"))
            } catch (e: Exception) {
                call.respond(HttpStatusCode.BadRequest, mapOf("success" to "false", "mensaje" to "Error: ${e.message}"))
            }
        }

        // ── CREAR USUARIO ────────────────────────────────────────
        post("/usuarios/nuevo") {
            try {
                val body = Json.decodeFromString<Map<String, JsonElement>>(call.receiveText())
                val nombre = body["nombre"]?.jsonPrimitive?.contentOrNull
                    ?: return@post call.respond(HttpStatusCode.BadRequest, mapOf("success" to "false", "mensaje" to "Falta nombre"))
                val email  = body["email"]?.jsonPrimitive?.contentOrNull
                    ?: return@post call.respond(HttpStatusCode.BadRequest, mapOf("success" to "false", "mensaje" to "Falta email"))
                val telefono   = body["telefono"]?.jsonPrimitive?.contentOrNull ?: ""
                val password   = body["password"]?.jsonPrimitive?.contentOrNull ?: "1234"
                val rol        = body["rol"]?.jsonPrimitive?.contentOrNull      ?: "Residente"
                val cerrada    = body["cerrada"]?.jsonPrimitive?.contentOrNull
                val numeroCasa = body["numeroCasa"]?.jsonPrimitive?.intOrNull
                var codigoGenerado: String?   = null
                var fechaVencimiento: String? = null
                transaction {
                    Usuarios.insert {
                        it[Usuarios.nombre] = nombre; it[Usuarios.email] = email
                        it[Usuarios.telefono] = telefono
                        it[Usuarios.password] = BCrypt.hashpw(password, BCrypt.gensalt())
                        it[Usuarios.rol] = rol; it[Usuarios.cerrada] = cerrada; it[Usuarios.numeroCasa] = numeroCasa
                    }
                    if (rol.lowercase().contains("residente")) {
                        val r = generarCodigoConVencimiento(email, nombre, "Sistema-Auto")
                        codigoGenerado = r["codigo"]; fechaVencimiento = r["fechaVencimiento"]
                    }
                }
                val respuesta = mutableMapOf("success" to "true", "mensaje" to "Usuario creado correctamente")
                if (codigoGenerado != null) { respuesta["codigo"] = codigoGenerado!!; respuesta["fechaVencimiento"] = fechaVencimiento!! }
                call.respond(respuesta)
            } catch (e: Exception) {
                call.respond(HttpStatusCode.BadRequest, mapOf("success" to "false", "mensaje" to "Error: ${e.message}"))
            }
        }

        // ── EDITAR USUARIO ───────────────────────────────────────
        put("/usuarios/{id}") {
            try {
                val id = call.parameters["id"]?.toIntOrNull()
                    ?: return@put call.respond(HttpStatusCode.BadRequest, mapOf("success" to "false", "mensaje" to "ID invalido"))
                val body = Json.decodeFromString<Map<String, JsonElement>>(call.receiveText())
                transaction {
                    Usuarios.update({ Usuarios.id eq id }) {
                        body["nombre"]?.jsonPrimitive?.contentOrNull?.let    { v -> it[Usuarios.nombre]   = v }
                        body["email"]?.jsonPrimitive?.contentOrNull?.let     { v -> it[Usuarios.email]    = v }
                        body["telefono"]?.jsonPrimitive?.contentOrNull?.let  { v -> it[Usuarios.telefono] = v }
                        body["password"]?.jsonPrimitive?.contentOrNull?.let  { v -> it[Usuarios.password] = BCrypt.hashpw(v, BCrypt.gensalt()) }
                        body["rol"]?.jsonPrimitive?.contentOrNull?.let       { v -> it[Usuarios.rol]      = v }
                        body["cerrada"]?.jsonPrimitive?.contentOrNull?.let   { v -> it[Usuarios.cerrada]  = v }
                        if (body.containsKey("numeroCasa"))
                            it[Usuarios.numeroCasa] = body["numeroCasa"]?.jsonPrimitive?.intOrNull
                    }
                }
                call.respond(mapOf("success" to "true", "mensaje" to "Usuario actualizado correctamente"))
            } catch (e: Exception) {
                call.respond(HttpStatusCode.BadRequest, mapOf("success" to "false", "mensaje" to "Error: ${e.message}"))
            }
        }

        // ── ELIMINAR USUARIO ─────────────────────────────────────
        delete("/usuarios/{id}") {
            try {
                val id = call.parameters["id"]?.toIntOrNull()
                    ?: return@delete call.respond(HttpStatusCode.BadRequest, mapOf("success" to "false", "mensaje" to "ID invalido"))
                transaction { Usuarios.deleteWhere { Usuarios.id eq id } }
                call.respond(mapOf("success" to "true", "mensaje" to "Usuario eliminado correctamente"))
            } catch (e: Exception) {
                call.respond(HttpStatusCode.BadRequest, mapOf("success" to "false", "mensaje" to "Error: ${e.message}"))
            }
        }

        // ════════════════════════════════════════════════════════
        // CODIGOS DE ACCESO
        // ════════════════════════════════════════════════════════

        post("/codigos/generar") {
            try {
                val body           = Json.decodeFromString<Map<String, String>>(call.receiveText())
                val generadoPor    = body["generadoPor"]    ?: "Sistema"
                val emailAsignado  = body["emailAsignado"]  ?: ""
                val nombreAsignado = body["nombreAsignado"] ?: ""
                val resultado = generarCodigoConVencimiento(
                    emailAsignado.ifBlank { null },
                    nombreAsignado.ifBlank { null },
                    generadoPor
                )
                call.respond(mapOf("success" to "true") + resultado)
            } catch (e: Exception) {
                call.respond(HttpStatusCode.BadRequest, mapOf("success" to "false", "mensaje" to "Error: ${e.message}"))
            }
        }

        get("/codigos") {
            try {
                val lista = transaction {
                    CodigosAcceso.selectAll().orderBy(CodigosAcceso.id, SortOrder.DESC).map {
                        mapOf(
                            "id"               to it[CodigosAcceso.id].toString(),
                            "codigo"           to it[CodigosAcceso.codigo],
                            "generadoPor"      to it[CodigosAcceso.generadoPor],
                            "emailAsignado"    to (it[CodigosAcceso.emailAsignado]  ?: ""),
                            "nombreAsignado"   to (it[CodigosAcceso.nombreAsignado] ?: "Sin asignar"),
                            "contadorUsos"     to it[CodigosAcceso.contadorUsos].toString(),
                            "ultimoUso"        to (it[CodigosAcceso.ultimoUso]       ?: "Nunca usado"),
                            "fechaCreacion"    to it[CodigosAcceso.fechaCreacion],
                            "fechaVencimiento" to (it[CodigosAcceso.fechaVencimiento] ?: ""),
                            "estado"           to it[CodigosAcceso.estado]
                        )
                    }
                }
                call.respond(lista)
            } catch (e: Exception) { call.respond(HttpStatusCode.InternalServerError, "Error: ${e.message}") }
        }

        delete("/codigos/{id}") {
            try {
                val id = call.parameters["id"]?.toIntOrNull()
                    ?: return@delete call.respond(HttpStatusCode.BadRequest, mapOf("success" to "false", "mensaje" to "ID invalido"))
                transaction { CodigosAcceso.deleteWhere { CodigosAcceso.id eq id } }
                call.respond(mapOf("success" to "true", "mensaje" to "Codigo eliminado"))
            } catch (e: Exception) {
                call.respond(HttpStatusCode.BadRequest, mapOf("success" to "false", "mensaje" to "Error: ${e.message}"))
            }
        }

        post("/codigos/validar") {
            try {
                val body   = Json.decodeFromString<Map<String, String>>(call.receiveText())
                val codigo = body["codigo"]?.trim()?.uppercase() ?: ""
                val email  = body["email"] ?: ""
                val nombre = body["nombre"] ?: ""
                if (codigo.length != 9) { call.respond(mapOf("success" to "false", "mensaje" to "El codigo debe tener 9 caracteres")); return@post }
                val ahora = LocalDateTime.now()
                val fecha = ahora.format(DateTimeFormatter.ofPattern("yyyy-MM-dd HH:mm:ss"))
                var valido = false; var mensaje = ""; var nombreUsa = ""
                transaction {
                    val registro = CodigosAcceso.select { CodigosAcceso.codigo eq codigo }.firstOrNull()
                    when {
                        registro == null -> mensaje = "El codigo no existe"
                        registro[CodigosAcceso.estado] == "vencido" -> mensaje = "El codigo ha vencido"
                        registro[CodigosAcceso.emailAsignado] != null &&
                                registro[CodigosAcceso.emailAsignado] != email -> mensaje = "Este codigo no esta asignado a tu cuenta"
                        registro[CodigosAcceso.fechaVencimiento] != null &&
                                LocalDateTime.parse(registro[CodigosAcceso.fechaVencimiento],
                                    DateTimeFormatter.ofPattern("yyyy-MM-dd HH:mm:ss")) < ahora -> mensaje = "El codigo ha vencido"
                        else -> {
                            nombreUsa = registro[CodigosAcceso.nombreAsignado] ?: nombre
                            CodigosAcceso.update({ CodigosAcceso.codigo eq codigo }) {
                                it[CodigosAcceso.contadorUsos] = registro[CodigosAcceso.contadorUsos] + 1
                                it[CodigosAcceso.ultimoUso]    = fecha
                            }
                            valido = true; mensaje = "Codigo valido - Acceso permitido"
                        }
                    }
                }
                call.respond(mapOf("success" to valido.toString(), "mensaje" to mensaje, "nombre" to nombreUsa, "usos" to if (valido) mensaje else ""))
            } catch (e: Exception) {
                call.respond(HttpStatusCode.BadRequest, mapOf("success" to "false", "mensaje" to "Error: ${e.message}"))
            }
        }

        post("/codigos/renovar-vencidos") {
            try {
                val ahora = LocalDateTime.now(); var codigosRenovados = 0
                transaction {
                    val vencidos = CodigosAcceso.select {
                        (CodigosAcceso.estado eq "activo") and (CodigosAcceso.fechaVencimiento.isNotNull())
                    }.filter { row ->
                        val fv = row[CodigosAcceso.fechaVencimiento]
                        fv != null && LocalDateTime.parse(fv, DateTimeFormatter.ofPattern("yyyy-MM-dd HH:mm:ss")) < ahora
                    }
                    vencidos.forEach { registro ->
                        val emailAsignado  = registro[CodigosAcceso.emailAsignado]
                        val nombreAsignado = registro[CodigosAcceso.nombreAsignado]
                        CodigosAcceso.update({ CodigosAcceso.id eq registro[CodigosAcceso.id] }) { it[CodigosAcceso.estado] = "vencido" }
                        if (emailAsignado != null && nombreAsignado != null) {
                            generarCodigoConVencimiento(emailAsignado, nombreAsignado, "Sistema-Renovacion"); codigosRenovados++
                        }
                    }
                }
                call.respond(mapOf("success" to "true", "mensaje" to "Se renovaron $codigosRenovados codigos vencidos", "codigosRenovados" to codigosRenovados.toString()))
            } catch (e: Exception) {
                call.respond(HttpStatusCode.BadRequest, mapOf("success" to "false", "mensaje" to "Error: ${e.message}"))
            }
        }

        post("/codigos/verificar") {
            try {
                val activos = transaction { CodigosAcceso.select { CodigosAcceso.estado eq "activo" }.count() }
                call.respond(mapOf("success" to "true", "codigosActivos" to activos.toString()))
            } catch (e: Exception) {
                call.respond(HttpStatusCode.BadRequest, mapOf("success" to "false", "mensaje" to "Error: ${e.message}"))
            }
        }

        // ── REGISTRAR O ACTUALIZAR PAGO ──────────────────────────
        post("/vigilancia/pago") {
            try {
                val body           = Json.decodeFromString<Map<String, String>>(call.receiveText())
                val emailResidente = body["emailResidente"] ?: return@post call.respond(HttpStatusCode.BadRequest, mapOf("success" to "false", "mensaje" to "Falta emailResidente"))
                val registradoPor  = body["registradoPor"]  ?: "Sistema"
                val fecha          = LocalDateTime.now().format(DateTimeFormatter.ofPattern("yyyy-MM-dd HH:mm:ss"))
                val vencimiento    = LocalDateTime.now().plusMonths(1).format(DateTimeFormatter.ofPattern("yyyy-MM-dd HH:mm:ss"))
                val usuario = transaction { Usuarios.select { Usuarios.email eq emailResidente }.firstOrNull() }
                    ?: return@post call.respond(HttpStatusCode.BadRequest, mapOf("success" to "false", "mensaje" to "Residente no encontrado"))
                transaction {
                    val existe = PagosVigilancia.select { PagosVigilancia.emailResidente eq emailResidente }.firstOrNull()
                    if (existe != null) {
                        PagosVigilancia.update({ PagosVigilancia.emailResidente eq emailResidente }) {
                            it[PagosVigilancia.estado] = "vigente"; it[PagosVigilancia.fechaPago] = fecha
                            it[PagosVigilancia.fechaVencimiento] = vencimiento; it[PagosVigilancia.registradoPor] = registradoPor
                        }
                    } else {
                        PagosVigilancia.insert {
                            it[PagosVigilancia.emailResidente] = emailResidente; it[PagosVigilancia.nombreResidente] = usuario[Usuarios.nombre]
                            it[PagosVigilancia.numeroCasa] = usuario[Usuarios.numeroCasa]; it[PagosVigilancia.cerrada] = usuario[Usuarios.cerrada]
                            it[PagosVigilancia.estado] = "vigente"; it[PagosVigilancia.fechaPago] = fecha
                            it[PagosVigilancia.fechaVencimiento] = vencimiento; it[PagosVigilancia.registradoPor] = registradoPor
                        }
                    }
                }
                call.respond(mapOf("success" to "true", "mensaje" to "Pago registrado correctamente"))
            } catch (e: Exception) {
                call.respond(HttpStatusCode.BadRequest, mapOf("success" to "false", "mensaje" to "Error: ${e.message}"))
            }
        }

        get("/vigilancia/pagos") {
            try {
                val lista = transaction {
                    PagosVigilancia.selectAll().orderBy(PagosVigilancia.id, SortOrder.DESC).map {
                        mapOf(
                            "id" to it[PagosVigilancia.id].toString(),
                            "emailResidente" to it[PagosVigilancia.emailResidente],
                            "nombreResidente" to it[PagosVigilancia.nombreResidente],
                            "numeroCasa" to (it[PagosVigilancia.numeroCasa]?.toString() ?: ""),
                            "cerrada" to (it[PagosVigilancia.cerrada] ?: ""),
                            "estado" to it[PagosVigilancia.estado],
                            "fechaPago" to (it[PagosVigilancia.fechaPago] ?: ""),
                            "fechaVencimiento" to (it[PagosVigilancia.fechaVencimiento] ?: ""),
                            "registradoPor" to it[PagosVigilancia.registradoPor]
                        )
                    }
                }
                call.respond(lista)
            } catch (e: Exception) { call.respond(HttpStatusCode.InternalServerError, "Error: ${e.message}") }
        }

        put("/vigilancia/pago/{email}") {
            try {
                val email  = call.parameters["email"] ?: return@put call.respond(HttpStatusCode.BadRequest, mapOf("success" to "false", "mensaje" to "Falta email"))
                val body   = Json.decodeFromString<Map<String, String>>(call.receiveText())
                val estado = body["estado"] ?: return@put call.respond(HttpStatusCode.BadRequest, mapOf("success" to "false", "mensaje" to "Falta estado"))
                transaction { PagosVigilancia.update({ PagosVigilancia.emailResidente eq email }) { it[PagosVigilancia.estado] = estado } }
                call.respond(mapOf("success" to "true", "mensaje" to "Estado actualizado"))
            } catch (e: Exception) {
                call.respond(HttpStatusCode.BadRequest, mapOf("success" to "false", "mensaje" to "Error: ${e.message}"))
            }
        }

        get("/vigilancia/estado/{email}") {
            try {
                val email = call.parameters["email"] ?: return@get call.respond(HttpStatusCode.BadRequest, mapOf("success" to "false", "mensaje" to "Falta email"))
                val usuario = transaction { Usuarios.select { Usuarios.email eq email }.firstOrNull() }
                    ?: return@get call.respond(HttpStatusCode.NotFound, mapOf("success" to "false", "mensaje" to "Residente no encontrado"))
                val pago = transaction { PagosVigilancia.select { PagosVigilancia.emailResidente eq email }.firstOrNull() }
                val ultimoAcceso = transaction {
                    BitacoraAccesos.select { BitacoraAccesos.emailResidente eq email }
                        .orderBy(BitacoraAccesos.id, SortOrder.DESC).firstOrNull()?.get(BitacoraAccesos.tipo)
                }
                val siguienteTipo = if (ultimoAcceso == "entrada") "salida" else "entrada"
                call.respond(mapOf(
                    "success" to "true", "nombre" to usuario[Usuarios.nombre], "email" to email,
                    "numeroCasa" to (usuario[Usuarios.numeroCasa]?.toString() ?: ""),
                    "cerrada" to (usuario[Usuarios.cerrada] ?: ""),
                    "estadoPago" to (pago?.get(PagosVigilancia.estado) ?: "sin_registro"),
                    "siguienteTipo" to siguienteTipo
                ))
            } catch (e: Exception) {
                call.respond(HttpStatusCode.InternalServerError, mapOf("success" to "false", "mensaje" to "Error: ${e.message}"))
            }
        }

        // ════════════════════════════════════════════════════════
        // BITACORA DE ACCESOS
        // ════════════════════════════════════════════════════════

        post("/bitacora/registrar") {
            try {
                val body           = Json.decodeFromString<Map<String, String>>(call.receiveText())
                val emailResidente = body["emailResidente"] ?: return@post call.respond(HttpStatusCode.BadRequest, mapOf("success" to "false", "mensaje" to "Falta emailResidente"))
                val tipo           = body["tipo"]           ?: return@post call.respond(HttpStatusCode.BadRequest, mapOf("success" to "false", "mensaje" to "Falta tipo"))
                val registradoPor  = body["registradoPor"]  ?: ""
                val fecha          = LocalDateTime.now().format(DateTimeFormatter.ofPattern("yyyy-MM-dd HH:mm:ss"))
                val estadoPago = transaction { PagosVigilancia.select { PagosVigilancia.emailResidente eq emailResidente }.firstOrNull()?.get(PagosVigilancia.estado) }
                if (estadoPago != "vigente") {
                    call.respond(mapOf("success" to "false", "mensaje" to "ACCESO DENEGADO — Pago pendiente o sin registro")); return@post
                }
                val usuario = transaction { Usuarios.select { Usuarios.email eq emailResidente }.firstOrNull() }
                    ?: return@post call.respond(HttpStatusCode.BadRequest, mapOf("success" to "false", "mensaje" to "Residente no encontrado"))
                transaction {
                    BitacoraAccesos.insert {
                        it[BitacoraAccesos.emailResidente]  = emailResidente
                        it[BitacoraAccesos.nombreResidente] = usuario[Usuarios.nombre]
                        it[BitacoraAccesos.numeroCasa]      = usuario[Usuarios.numeroCasa]
                        it[BitacoraAccesos.cerrada]         = usuario[Usuarios.cerrada]
                        it[BitacoraAccesos.tipo]            = tipo
                        it[BitacoraAccesos.fecha]           = fecha
                        it[BitacoraAccesos.registradoPor]   = registradoPor.ifBlank { null }
                    }
                }
                call.respond(mapOf(
                    "success" to "true",
                    "mensaje" to "${tipo.replaceFirstChar { it.uppercase() }} registrada correctamente",
                    "nombre" to usuario[Usuarios.nombre], "tipo" to tipo, "fecha" to fecha
                ))
            } catch (e: Exception) {
                call.respond(HttpStatusCode.BadRequest, mapOf("success" to "false", "mensaje" to "Error: ${e.message}"))
            }
        }

        get("/bitacora") {
            try {
                val lista = transaction {
                    BitacoraAccesos.selectAll().orderBy(BitacoraAccesos.id, SortOrder.DESC).map {
                        mapOf(
                            "id" to it[BitacoraAccesos.id].toString(),
                            "emailResidente"  to it[BitacoraAccesos.emailResidente],
                            "nombreResidente" to it[BitacoraAccesos.nombreResidente],
                            "numeroCasa"      to (it[BitacoraAccesos.numeroCasa]?.toString() ?: ""),
                            "cerrada"         to (it[BitacoraAccesos.cerrada]       ?: ""),
                            "tipo"            to it[BitacoraAccesos.tipo],
                            "fecha"           to it[BitacoraAccesos.fecha],
                            "registradoPor"   to (it[BitacoraAccesos.registradoPor] ?: "")
                        )
                    }
                }
                call.respond(lista)
            } catch (e: Exception) { call.respond(HttpStatusCode.InternalServerError, "Error: ${e.message}") }
        }
    }
}