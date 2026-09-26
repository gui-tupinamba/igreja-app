import { useState } from "react";

import {
  Image,
  KeyboardAvoidingView,
  Platform,
  Pressable,
  ScrollView,
  StyleSheet,
  Text,
  TextInput,
  View,
} from "react-native";

import { SafeAreaView } from "react-native-safe-area-context";

import { Ionicons } from "@expo/vector-icons";

import { ErrorState } from "@/components";
import { useSession } from "@/session";
import { colors } from "@/theme";

export default function SignIn() {
  const { login } = useSession();

  const [email, setEmail] = useState("");
  const [password, setPassword] = useState("");

  const [showPassword, setShowPassword] = useState(false);

  const [error, setError] = useState<unknown>();

  const [busy, setBusy] = useState(false);

  const canSubmit = email.trim().length > 0 && password.length > 0 && !busy;

  async function submit() {
    if (!canSubmit) {
      return;
    }

    setBusy(true);
    setError(undefined);

    try {
      await login(email.trim(), password);
    } catch (reason) {
      setError(reason);
    } finally {
      setBusy(false);
    }
  }

  return (
    <SafeAreaView style={styles.safe}>
      {/* DECORAÇÃO */}

      <View pointerEvents="none" style={styles.decorations}>
        <View style={styles.circleLarge} />
        <View style={styles.circleSmall} />

        <View style={styles.leafOne} />
        <View style={styles.leafTwo} />
        <View style={styles.leafThree} />
      </View>

      <KeyboardAvoidingView
        behavior={Platform.OS === "ios" ? "padding" : undefined}
        style={styles.keyboard}
      >
        <ScrollView
          keyboardShouldPersistTaps="handled"
          showsVerticalScrollIndicator={false}
          contentContainerStyle={styles.scrollContent}
        >
          {/* MARCA */}

          <View style={styles.brand}>
            <View style={styles.logoContainer}>
              <Image
                source={require("../assets/images/logo.jpg")}
                style={styles.logo}
                resizeMode="contain"
              />
            </View>

            <View>
              <Text style={styles.brandName}>Comunidade</Text>

              <Text style={styles.brandTag}>VIDA EM IGREJA</Text>
            </View>
          </View>

          {/* APRESENTAÇÃO */}

          <View style={styles.copy}>
            <Text style={styles.eyebrow}>BEM-VINDO À SUA COMUNIDADE</Text>

            <Text accessibilityRole="header" style={styles.title}>
              Que bom ter{"\n"}você aqui.
            </Text>

            <Text style={styles.subtitle}>
              Entre para acompanhar sua igreja.
            </Text>
          </View>

          {/* ERRO */}

          {error ? (
            <View style={styles.error}>
              <ErrorState error={error} />
            </View>
          ) : null}

          {/* E-MAIL */}

          <Text style={styles.label}>E-mail</Text>

          <View style={styles.inputContainer}>
            <Ionicons name="mail-outline" size={22} color={colors.muted} />

            <TextInput
              accessibilityLabel="E-mail"
              autoCapitalize="none"
              autoCorrect={false}
              autoComplete="email"
              keyboardType="email-address"
              value={email}
              onChangeText={setEmail}
              placeholder="Seu melhor e-mail"
              placeholderTextColor="#95a39f"
              style={styles.input}
              returnKeyType="next"
            />
          </View>

          {/* SENHA */}

          <Text style={styles.label}>Senha</Text>

          <View style={styles.inputContainer}>
            <Ionicons
              name="lock-closed-outline"
              size={22}
              color={colors.muted}
            />

            <TextInput
              accessibilityLabel="Senha"
              autoComplete="current-password"
              secureTextEntry={!showPassword}
              value={password}
              onChangeText={setPassword}
              placeholder="Sua senha"
              placeholderTextColor="#95a39f"
              style={styles.input}
              returnKeyType="done"
              onSubmitEditing={submit}
            />

            <Pressable
              accessibilityRole="button"
              accessibilityLabel={
                showPassword ? "Ocultar senha" : "Mostrar senha"
              }
              hitSlop={10}
              onPress={() => setShowPassword((value) => !value)}
              style={({ pressed }) => [
                styles.eyeButton,
                pressed && styles.pressed,
              ]}
            >
              <Ionicons
                name={showPassword ? "eye-outline" : "eye-off-outline"}
                size={23}
                color={colors.muted}
              />
            </Pressable>
          </View>

          {/* ENTRAR */}

          <Pressable
            accessibilityRole="button"
            disabled={!canSubmit}
            onPress={submit}
            style={({ pressed }) => [
              styles.loginButton,

              !canSubmit && styles.loginButtonDisabled,

              pressed && canSubmit && styles.pressed,
            ]}
          >
            <Text style={styles.loginButtonText}>
              {busy ? "Entrando…" : "Entrar na comunidade"}
            </Text>

            {!busy ? (
              <Ionicons name="arrow-forward" size={22} color={colors.white} />
            ) : null}
          </Pressable>

          {/* RODAPÉ */}

          <View style={styles.divider}>
            <View style={styles.dividerLine} />

            <View style={styles.peopleIcon}>
              <Ionicons name="people-outline" size={20} color={colors.green} />
            </View>

            <View style={styles.dividerLine} />
          </View>

          <Text style={styles.help}>
            Ainda não tem acesso? Fale com a administração da sua igreja.
          </Text>
        </ScrollView>
      </KeyboardAvoidingView>
    </SafeAreaView>
  );
}

const styles = StyleSheet.create({
  logoContainer: {
    width: 64,
    height: 64,

    alignItems: "center",
    justifyContent: "center",
  },

  logo: {
    width: 58,
    height: 58,
  },

  safe: {
    flex: 1,
    backgroundColor: colors.cream,
  },

  keyboard: {
    flex: 1,
  },

  scrollContent: {
    flexGrow: 1,

    paddingHorizontal: 28,
    paddingTop: 52,
    paddingBottom: 38,
  },

  /* DECORAÇÃO */

  decorations: {
    ...StyleSheet.absoluteFill,
    overflow: "hidden",
  },

  circleLarge: {
    position: "absolute",

    width: 280,
    height: 280,

    borderRadius: 140,

    right: -145,
    top: -115,

    backgroundColor: "rgba(23,85,72,0.045)",
  },

  circleSmall: {
    position: "absolute",

    width: 175,
    height: 175,

    borderRadius: 88,

    right: -65,
    top: 34,

    borderWidth: 1,
    borderColor: "rgba(23,85,72,0.055)",
  },

  leafOne: {
    position: "absolute",

    width: 90,
    height: 34,

    borderRadius: 50,

    right: -16,
    top: 155,

    backgroundColor: "rgba(23,85,72,0.055)",

    transform: [
      {
        rotate: "35deg",
      },
    ],
  },

  leafTwo: {
    position: "absolute",

    width: 80,
    height: 30,

    borderRadius: 50,

    right: 17,
    top: 199,

    backgroundColor: "rgba(23,85,72,0.045)",

    transform: [
      {
        rotate: "-25deg",
      },
    ],
  },

  leafThree: {
    position: "absolute",

    width: 70,
    height: 27,

    borderRadius: 50,

    right: -3,
    top: 240,

    backgroundColor: "rgba(23,85,72,0.04)",

    transform: [
      {
        rotate: "25deg",
      },
    ],
  },

  /* MARCA */

  brand: {
    flexDirection: "row",
    alignItems: "center",

    gap: 15,

    marginBottom: 52,
  },

  brandMark: {
    width: 66,
    height: 66,

    borderRadius: 18,

    backgroundColor: colors.green,

    alignItems: "center",
    justifyContent: "center",

    shadowColor: "#000",
    shadowOffset: {
      width: 0,
      height: 5,
    },
    shadowOpacity: 0.11,
    shadowRadius: 10,

    elevation: 4,
  },

  brandText: {
    justifyContent: "center",
  },

  brandName: {
    color: colors.ink,

    fontSize: 27,
    lineHeight: 31,

    fontWeight: "900",
  },

  brandTag: {
    color: colors.muted,

    fontSize: 10,

    letterSpacing: 2.6,

    fontWeight: "800",

    marginTop: 4,
  },

  /* TEXTO */

  copy: {
    marginBottom: 31,
  },

  eyebrow: {
    color: colors.green,

    fontSize: 11,

    letterSpacing: 1.8,

    fontWeight: "900",

    marginBottom: 12,
  },

  title: {
    color: colors.ink,

    fontSize: 38,
    lineHeight: 43,

    fontWeight: "900",

    letterSpacing: -0.7,
  },

  subtitle: {
    color: colors.muted,

    fontSize: 16,
    lineHeight: 23,

    marginTop: 11,
  },

  /* FORMULÁRIO */

  label: {
    color: colors.ink,

    fontSize: 15,

    fontWeight: "800",

    marginBottom: 8,
  },

  inputContainer: {
    minHeight: 60,

    flexDirection: "row",
    alignItems: "center",

    gap: 11,

    backgroundColor: colors.white,

    borderWidth: 1,
    borderColor: colors.border,

    borderRadius: 16,

    paddingHorizontal: 16,

    marginBottom: 21,

    shadowColor: "#000",
    shadowOffset: {
      width: 0,
      height: 2,
    },
    shadowOpacity: 0.025,
    shadowRadius: 6,

    elevation: 1,
  },

  input: {
    flex: 1,

    color: colors.ink,

    fontSize: 16,

    paddingVertical: 0,
  },

  eyeButton: {
    width: 38,
    height: 38,

    alignItems: "center",
    justifyContent: "center",
  },

  /* BOTÃO */

  loginButton: {
    minHeight: 60,

    flexDirection: "row",
    alignItems: "center",
    justifyContent: "center",

    gap: 14,

    borderRadius: 18,

    backgroundColor: colors.green,

    paddingHorizontal: 18,

    marginTop: 3,

    shadowColor: colors.greenDark,
    shadowOffset: {
      width: 0,
      height: 7,
    },
    shadowOpacity: 0.17,
    shadowRadius: 11,

    elevation: 4,
  },

  loginButtonDisabled: {
    opacity: 0.55,
  },

  loginButtonText: {
    color: colors.white,

    fontSize: 17,

    fontWeight: "900",
  },

  /* RODAPÉ */

  divider: {
    flexDirection: "row",
    alignItems: "center",

    gap: 12,

    marginTop: 37,
    marginBottom: 18,
  },

  dividerLine: {
    flex: 1,

    height: 1,

    backgroundColor: colors.border,
  },

  peopleIcon: {
    width: 42,
    height: 42,

    borderRadius: 21,

    backgroundColor: colors.greenSoft,

    alignItems: "center",
    justifyContent: "center",
  },

  help: {
    maxWidth: 300,

    alignSelf: "center",

    color: colors.muted,

    textAlign: "center",

    fontSize: 14,
    lineHeight: 21,
  },

  /* OUTROS */

  error: {
    marginBottom: 16,
  },

  pressed: {
    opacity: 0.75,
  },
});
