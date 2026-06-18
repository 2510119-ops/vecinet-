package com.example.plugins

import com.zaxxer.hikari.HikariConfig
import com.zaxxer.hikari.HikariDataSource
import io.ktor.server.application.*
import org.jetbrains.exposed.sql.*
import org.jetbrains.exposed.sql.transactions.transaction

object Usuarios : Table("usuarios") {
    val id         = integer("id").autoIncrement()
    val nombre     = varchar("nombre", 100)
    val email      = varchar("email", 100)
    val telefono   = varchar("telefono", 15)
    val password   = varchar("password", 255)
    val rol        = varchar("rol", 50)
    val fotoPerfil = varchar("foto_perfil", 500).nullable()
    val cerrada    = varchar("cerrada", 100).nullable()
    val numeroCasa = integer("numeroCasa").nullable()
    val fcmToken   = text("fcmToken").nullable()
    override val primaryKey = PrimaryKey(id)
}

object Visitantes : Table("visitantes") {
    val id         = integer("id").autoIncrement()
    val nombre     = varchar("nombre", 100)
    val email      = varchar("email", 100)
    val telefono   = varchar("telefono", 15)
    val password   = varchar("password", 255)
    val rol        = varchar("rol", 50)
    val fotoPerfil = varchar("foto_perfil", 500).nullable()
    override val primaryKey = PrimaryKey(id)
}

object Reportes : Table("reportes") {
    val id            = integer("id").autoIncrement()
    val usuarioEmail  = varchar("usuario_email", 100)
    val usuarioNombre = varchar("usuario_nombre", 100)
    val tipo          = varchar("tipo", 100)
    val nivel         = integer("nivel")
    val descripcion   = text("descripcion").nullable()
    val fotoUrl       = varchar("foto_url", 500).nullable()
    val latitud       = double("latitud").nullable()
    val longitud      = double("longitud").nullable()
    val fecha         = varchar("fecha", 50)
    val estado        = varchar("estado", 20).default("activo")
    override val primaryKey = PrimaryKey(id)
}

object CodigosAcceso : Table("codigos_acceso") {
    val id               = integer("id").autoIncrement()
    val codigo           = varchar("codigo", 9)
    val generadoPor      = varchar("generadoPor", 100)
    val emailAsignado    = varchar("emailAsignado", 100).nullable()
    val nombreAsignado   = varchar("nombreAsignado", 100).nullable()
    val contadorUsos     = integer("contadorUsos").default(0)
    val ultimoUso        = text("ultimoUso").nullable()
    val fechaCreacion    = text("fechaCreacion")
    val fechaVencimiento = text("fechaVencimiento").nullable()
    val estado           = varchar("estado", 20).default("activo") // "activo", "vencido"
    override val primaryKey = PrimaryKey(id)
}

object PagosVigilancia : Table("pagos_vigilancia") {
    val id               = integer("id").autoIncrement()
    val emailResidente   = varchar("emailResidente", 100)
    val nombreResidente  = varchar("nombreResidente", 100)
    val numeroCasa       = integer("numeroCasa").nullable()
    val cerrada          = varchar("cerrada", 100).nullable()
    val estado           = varchar("estado", 20).default("pendiente")
    val fechaPago        = text("fechaPago").nullable()
    val fechaVencimiento = text("fechaVencimiento").nullable()
    val registradoPor    = varchar("registradoPor", 100)
    override val primaryKey = PrimaryKey(id)
}

object BitacoraAccesos : Table("bitacora_accesos") {
    val id              = integer("id").autoIncrement()
    val emailResidente  = varchar("emailResidente", 100)
    val nombreResidente = varchar("nombreResidente", 100)
    val numeroCasa      = integer("numeroCasa").nullable()
    val cerrada         = varchar("cerrada", 100).nullable()
    val tipo            = varchar("tipo", 10)  // "entrada" o "salida"
    val fecha           = text("fecha")
    val registradoPor   = varchar("registradoPor", 100).nullable()
    override val primaryKey = PrimaryKey(id)
}

fun Application.configureDatabase() {
    val host     = System.getenv("MYSQLHOST")      ?: "localhost"
    val port     = System.getenv("MYSQLPORT")      ?: "3306"
    val database = System.getenv("MYSQL_DATABASE") ?: System.getenv("MYSQLDATABASE") ?: "Vecinet_App"
    val user     = System.getenv("MYSQLUSER")      ?: "root"
    val pass     = System.getenv("MYSQLPASSWORD")  ?: ""

    val config = HikariConfig().apply {
        jdbcUrl = "jdbc:mysql://$host:$port/$database?useSSL=false&allowPublicKeyRetrieval=true&serverTimezone=UTC"
        driverClassName = "com.mysql.cj.jdbc.Driver"
        username = user
        password = pass
        maximumPoolSize = 3
    }
    Database.connect(HikariDataSource(config))

    transaction {
        // createMissingTablesAndColumns agrega columnas nuevas a tablas existentes
        // a diferencia de create() que solo crea tablas si no existen
        SchemaUtils.createMissingTablesAndColumns(
            Usuarios,
            Visitantes,
            Reportes,
            CodigosAcceso,
            PagosVigilancia,
            BitacoraAccesos
        )
    }
}